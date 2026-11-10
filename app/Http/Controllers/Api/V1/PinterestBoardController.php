<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\SocialPlatform;
use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use App\Support\SocialProviders;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * A Pinterest channel's boards, for the composer's board picker — a Pin
 * cannot go out without one (`options.{account}.board_id` on a post).
 */
class PinterestBoardController extends Controller
{
    public function __invoke(Request $request, SocialAccount $socialAccount, SocialProviders $providers): JsonResponse
    {
        Gate::allowIf(fn ($user) => $socialAccount->user_id === $user->id);

        if ($socialAccount->platform !== SocialPlatform::Pinterest) {
            return response()->json(['message' => 'Only a Pinterest channel has boards.'], 422);
        }

        try {
            $boards = $providers->for($socialAccount)->pinterestBoards($socialAccount->provider_account_id);
        } catch (Throwable) {
            return response()->json(['message' => 'Pinterest did not answer. Try again in a moment.'], 502);
        }

        return response()->json(['data' => $boards]);
    }
}
