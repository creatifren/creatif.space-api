<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ChannelCheckUnavailable;
use App\Http\Controllers\Controller;
use App\Http\Resources\SocialAccountResource;
use App\Models\SocialAccount;
use App\Support\ChannelHealth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * POST /api/v1/social-accounts/{id}/verify — "Check now": one live,
 * read-only check of a connected channel (ChannelHealth), answered with
 * the channel as GET /social-accounts lists it. Once a minute per channel.
 * No email: the person asking sees the answer.
 */
class ChannelVerifyController extends Controller
{
    public const PER_MINUTE = 1;

    public function __invoke(Request $request, SocialAccount $socialAccount, ChannelHealth $health): JsonResponse
    {
        Gate::allowIf(fn ($user) => $socialAccount->user_id === $user->id);

        if ($socialAccount->removed_at !== null) {
            abort(404);
        }

        if (! $socialAccount->isUsable()) {
            return response()->json([
                'message' => 'This channel is not connected. Reconnect it, then check it.',
                'reason' => 'not_connected',
            ], 409);
        }

        $key = 'channel-verify:'.$socialAccount->id;
        if (RateLimiter::tooManyAttempts($key, self::PER_MINUTE)) {
            $wait = max(1, RateLimiter::availableIn($key));

            return response()->json([
                'message' => "This channel was just checked. Try again in {$wait} seconds.",
                'reason' => 'too_soon',
                'retry_after' => $wait,
            ], 429, ['Retry-After' => (string) $wait]);
        }
        RateLimiter::hit($key, 60);

        try {
            $health->check($socialAccount);
        } catch (ChannelCheckUnavailable $e) {
            Log::warning('social.channel_check_unavailable', ['account' => $socialAccount->ulid, 'error' => $e->getMessage()]);

            return response()->json([
                'message' => 'The check could not reach the network right now. Try again in a minute.',
                'reason' => 'upstream_unavailable',
            ], 503, ['Retry-After' => '60']);
        }

        return (new SocialAccountResource($socialAccount))->response();
    }
}
