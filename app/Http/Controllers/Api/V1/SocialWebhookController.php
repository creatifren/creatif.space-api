<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\SocialAccountStatus;
use App\Enums\SocialPlatform;
use App\Enums\SocialPostStatus;
use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use App\Contracts\SocialProvider;
use App\Support\SocialPostRelay;
use App\Support\SocialProviders;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * A relay calls this on account and post events — one route per relay,
 * the route says which. Public: the provider's signature (or shared
 * secret) is the authentication. Unknown events and unknown ids answer
 * 200 — a non-2XX makes the provider retry, and there is nothing a
 * redelivery of an unknown event could fix. Only rows that relay holds
 * are touched.
 */
class SocialWebhookController extends Controller
{
    private SocialProvider $provider;

    public function __invoke(Request $request, SocialProviders $providers, string $provider): JsonResponse
    {
        $this->provider = $providers->named($provider);

        if (! $this->provider->verifyWebhook($request)) {
            return response()->json(['message' => 'Invalid signature.'], 403);
        }

        foreach ($this->provider->webhookEvents($request) as $event) {
            match ($event['type']) {
                'account' => $this->upsertAccount($event['data']),
                'account.lost' => $this->loseAccount($event['data']),
                'post' => $this->updatePost($event['data']),
                'result' => $this->recordResult($event['data']),
                default => null,
            };
        }

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
                'provider' => $this->provider->name(),
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
     * The account's grant stopped working (Outstand: its token would not
     * refresh). It is Disconnected until the user connects it again.
     *
     * @param  array<string, mixed>  $data
     */
    private function loseAccount(array $data): void
    {
        $id = (string) ($data['id'] ?? '');

        if ($id === '') {
            return;
        }

        SocialAccount::query()
            ->where('provider', $this->provider->name())
            ->where('provider_account_id', $id)
            ->update(['status' => SocialAccountStatus::Disconnected]);
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
            ->where('provider', $this->provider->name())
            ->where('provider_post_id', (string) ($data['post_id'] ?? ''))
            ->first();

        // The post's own target, not any row with the id: the same platform
        // account can sit under two of our users (one Disconnected).
        $account = $post?->targets()->with('account')->get()
            ->first(fn ($t) => $t->account->provider_account_id === (string) ($data['social_account_id'] ?? ''))
            ?->account;

        if ($post === null || $account === null) {
            return;
        }

        SocialPostRelay::applyResult($post, $account, $data);
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
            ->where('provider', $this->provider->name())
            ->when($providerId !== '', fn ($q) => $q->where('provider_post_id', $providerId))
            ->when(
                $providerId === '' && $externalId !== '',
                fn ($q) => $q->where('ulid', $externalId),
            )
            ->when($providerId === '' && $externalId === '', fn ($q) => $q->whereRaw('1 = 0'))
            ->first();
    }
}
