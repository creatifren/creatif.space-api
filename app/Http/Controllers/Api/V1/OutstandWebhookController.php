<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\SocialAccountStatus;
use App\Enums\SocialPostTargetStatus;
use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use App\Models\SocialPostTarget;
use App\Services\OutstandService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Outstand calls this on post and account events. Public route: the HMAC
 * signature is the authentication. Unknown events and unknown ids answer
 * 200 — a non-2XX makes the provider retry, and there is nothing a
 * redelivery of an unknown event could fix.
 */
class OutstandWebhookController extends Controller
{
    public function __invoke(Request $request, OutstandService $outstand): JsonResponse
    {
        if (! $outstand->verifyWebhook($request)) {
            return response()->json(['message' => 'Invalid signature.'], 403);
        }

        $data = (array) $request->input('data', []);

        match ((string) $request->input('event')) {
            'post.published' => $this->recordResults($data, true),
            'post.error' => $this->recordResults($data, false),
            'account.token_expired' => $this->expireAccount($data),
            default => null,
        };

        return response()->json(['message' => 'ok']);
    }

    /**
     * One outcome per destination account. Redeliveries overwrite the same
     * values — idempotent by construction.
     *
     * @param  array<string, mixed>  $data
     */
    private function recordResults(array $data, bool $success): void
    {
        $postId = (string) ($data['postId'] ?? '');

        foreach ((array) ($data['socialAccounts'] ?? []) as $result) {
            // accountId is a string here but a number in other events.
            $target = SocialPostTarget::query()
                ->where('provider_post_id', $postId)
                ->whereHas('account', fn ($q) => $q
                    ->where('provider_account_id', (string) ($result['accountId'] ?? '')))
                ->first();

            if ($target === null) {
                continue;
            }

            DB::transaction(function () use ($target, $result, $success): void {
                $target->update($success
                    ? [
                        'status' => SocialPostTargetStatus::Published,
                        'fail_reason' => null,
                        'platform_url' => $result['platformPostUrl'] ?? null,
                        'provider_result_id' => $result['platformPostId'] ?? null,
                    ]
                    : [
                        'status' => SocialPostTargetStatus::Failed,
                        'fail_reason' => is_string($result['error'] ?? null)
                            ? $result['error']
                            : json_encode($result['error'] ?? 'Unknown error'),
                    ]);

                $target->post->recomputeFromTargets();
            });
        }
    }

    /**
     * The platform grant lapsed; the user has to connect again.
     *
     * @param  array<string, mixed>  $data
     */
    private function expireAccount(array $data): void
    {
        SocialAccount::query()
            ->where('provider_account_id', (string) ($data['accountId'] ?? ''))
            ->update(['status' => SocialAccountStatus::Expired]);
    }
}
