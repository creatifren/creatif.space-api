<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\AccountReconnectRequired;
use App\Exceptions\NotEnoughCredits;
use App\Exceptions\PostNotRetryable;
use App\Http\Controllers\Controller;
use App\Http\Resources\SocialPostResource;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use App\Support\Credits;
use App\Support\SendConfirmation;
use App\Support\SocialPostRelay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Try a failed publish again (the post's failed destinations only).
 *
 * GET  /social-posts/{post}/retry-preview — what would go again, why each
 *      failed (failure_code + reason), the price, and for a connected app
 *      a confirm_token (10 minutes, over these destinations and price).
 * POST /social-posts/{post}/retry — re-queues them: paid like any send
 *      (a failed destination was paid back, so it is charged again), 409
 *      not_failed when nothing failed. A bearer caller must bring the
 *      preview's confirm_token (MCP retry_post: preview → confirm).
 *
 * The post's owner only, like send; another workspace's post is 404.
 */
class PublishRetryController extends Controller
{
    public function preview(Request $request, SocialPost $socialPost): JsonResponse
    {
        Gate::allowIf(fn ($user) => $socialPost->user_id === $user->id);

        $user = $request->user();
        $failed = SocialPostRelay::failedTargets($socialPost);

        $blocked = null;
        try {
            SocialPostRelay::requireRetryable($socialPost, $failed);
        } catch (PostNotRetryable $e) {
            $blocked = ['code' => $e->errorCode, 'message' => $e->getMessage()];
        } catch (AccountReconnectRequired $e) {
            $blocked = ['code' => AccountReconnectRequired::CODE, 'message' => $e->getMessage()];
        } catch (NotEnoughCredits $e) {
            $blocked = ['code' => $e->errorCode, 'message' => $e->getMessage()];
        }

        $targets = $failed->map(fn (SocialPostTarget $t) => [
            'channel_id' => $t->account?->ulid,
            'platform' => $t->account?->platform,
            'username' => $t->account?->username,
            'usable' => (bool) $t->account?->isUsable(),
            'failure_code' => $t->fail_code ?? 'network_rejected',
            'reason' => $t->fail_reason,
            'credits' => Credits::priceFor($socialPost, $t),
        ])->values();

        return response()->json(['data' => [
            'post_id' => $socialPost->ulid,
            'status' => $socialPost->status,
            'retryable' => $blocked === null,
            'blocked' => $blocked,
            'targets' => $targets->all(),
            'credits' => [
                'price' => (int) $targets->sum('credits'),
                'from_topup' => Credits::sendPrice($user, SocialPostRelay::retryDestinations($socialPost, $failed)),
                'monthly_balance' => Credits::monthlyBalance($user),
                'topup_balance' => Credits::balance($user),
            ],
            ...($blocked === null ? [
                'confirm_token' => SendConfirmation::issue($user, $socialPost, SendConfirmation::RETRY),
                'confirm_expires_at' => now()->addMinutes(SendConfirmation::TTL_MINUTES)->toIso8601String(),
            ] : []),
        ]]);
    }

    public function store(Request $request, SocialPost $socialPost, SocialPostRelay $relay): JsonResponse
    {
        Gate::allowIf(fn ($user) => $socialPost->user_id === $user->id);

        $validated = $request->validate([
            'confirm_token' => ['sometimes', 'nullable', 'string', 'max:1024'],
        ]);

        if (SendConfirmation::viaToken($request)) {
            if (empty($validated['confirm_token'])) {
                return response()->json([
                    'message' => 'Retrying from a connected app needs the confirm_token from GET /social-posts/{post}/retry-preview.',
                    'code' => 'confirm_token_required',
                ], 422);
            }
            if (SocialPostRelay::failedTargets($socialPost)->isEmpty()) {
                throw PostNotRetryable::notFailed();
            }
            if (! SendConfirmation::matches($request->user(), $socialPost, $validated['confirm_token'], SendConfirmation::RETRY)) {
                return response()->json([
                    'message' => 'The preview is out of date (destinations or Credits changed, or 10 minutes passed). Show the user a new preview and confirm again.',
                    'code' => 'preview_stale',
                ], 409);
            }
        }

        $retried = $relay->retryFailed($socialPost);

        return (new SocialPostResource($socialPost->fresh()->load('targets.account')))
            ->additional(['retried' => $retried])
            ->response();
    }
}
