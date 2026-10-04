<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\SocialAccountStatus;
use App\Enums\SocialPlatform;
use App\Enums\SocialPostStatus;
use App\Enums\SocialPostTargetStatus;
use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use App\Services\PostForMeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Post for Me calls this on account and post events. Public route: the
 * shared secret header is the authentication. Unknown events and unknown
 * ids answer 200 — a non-2XX makes the provider retry ~8 times, and there
 * is nothing a redelivery of an unknown event could fix.
 */
class PostForMeWebhookController extends Controller
{
    public function __invoke(Request $request, PostForMeService $postForMe): JsonResponse
    {
        if (! $postForMe->verifyWebhook($request)) {
            return response()->json(['message' => 'Invalid signature.'], 403);
        }

        // The wrapper's field name is not pinned down by the docs — accept
        // both spellings rather than dropping deliveries.
        $event = (string) ($request->input('event_type') ?? $request->input('type'));
        $data = (array) $request->input('data', []);

        match ($event) {
            'social.account.created',
            'social.account.updated' => $this->upsertAccount($data),
            'social.post.updated' => $this->updatePost($data),
            'social.post.result.created' => $this->recordResult($data),
            default => null,
        };

        return response()->json(['message' => 'ok']);
    }

    /**
     * An account was connected or changed. external_id carries our user's
     * ulid — an unknown one is someone else's workspace noise.
     *
     * @param  array<string, mixed>  $data
     */
    private function upsertAccount(array $data): void
    {
        $user = User::query()->where('ulid', (string) ($data['external_id'] ?? ''))->first();
        $platform = SocialPlatform::tryFrom((string) ($data['platform'] ?? ''));
        $providerId = (string) ($data['id'] ?? '');

        if ($user === null || $platform === null || $providerId === '') {
            return;
        }

        $user->socialAccounts()->updateOrCreate(
            [
                'platform' => $platform,
                'provider_account_id' => $providerId,
            ],
            [
                'username' => $data['username'] ?? null,
                'profile_photo_url' => $data['profile_photo_url'] ?? null,
                'status' => ($data['status'] ?? 'connected') === 'connected'
                    ? SocialAccountStatus::Connected
                    : SocialAccountStatus::Disconnected,
                'connected_at' => now(),
            ],
        );
    }

    /**
     * The post itself moved. `processed` alone doesn't decide the outcome —
     * per-target results do — so it publishes only until the targets say
     * otherwise; recompute has the final word.
     *
     * @param  array<string, mixed>  $data
     */
    private function updatePost(array $data): void
    {
        $post = $this->findPost($data);

        if ($post === null) {
            return;
        }

        DB::transaction(function () use ($post, $data): void {
            if (($data['status'] ?? null) === 'processed'
                && $post->status === SocialPostStatus::Scheduled) {
                $post->status = SocialPostStatus::Published;
            }

            $post->raw_payload = $data;
            $post->save();

            $post->recomputeFromTargets();
        });
    }

    /**
     * One destination's outcome. Redeliveries overwrite the same values —
     * idempotent by construction.
     *
     * @param  array<string, mixed>  $data
     */
    private function recordResult(array $data): void
    {
        $post = SocialPost::query()
            ->where('provider_post_id', (string) ($data['post_id'] ?? ''))
            ->first();

        $account = SocialAccount::query()
            ->where('provider_account_id', (string) ($data['social_account_id'] ?? ''))
            ->first();

        if ($post === null || $account === null) {
            return;
        }

        $target = $post->targets()->where('social_account_id', $account->id)->first();

        if ($target === null) {
            return;
        }

        DB::transaction(function () use ($post, $target, $data): void {
            $success = (bool) ($data['success'] ?? false);

            $target->update($success
                ? [
                    'status' => SocialPostTargetStatus::Published,
                    'fail_reason' => null,
                    'platform_url' => $data['platform_data']['url']
                        ?? $data['details']['url']
                        ?? null,
                    'provider_result_id' => $data['id'] ?? null,
                ]
                : [
                    'status' => SocialPostTargetStatus::Failed,
                    'fail_reason' => is_string($data['error'] ?? null)
                        ? $data['error']
                        : json_encode($data['error'] ?? 'Unknown error'),
                    'provider_result_id' => $data['id'] ?? null,
                ]);

            $post->recomputeFromTargets();
        });
    }

    /**
     * By provider post id, falling back to our own ulid sent as
     * external_id — covers a webhook racing the create response.
     *
     * @param  array<string, mixed>  $data
     */
    private function findPost(array $data): ?SocialPost
    {
        $providerId = (string) ($data['id'] ?? '');
        $externalId = (string) ($data['external_id'] ?? '');

        return SocialPost::query()
            ->when($providerId !== '', fn ($q) => $q->where('provider_post_id', $providerId))
            ->when(
                $providerId === '' && $externalId !== '',
                fn ($q) => $q->where('ulid', $externalId),
            )
            ->when($providerId === '' && $externalId === '', fn ($q) => $q->whereRaw('1 = 0'))
            ->first();
    }
}
