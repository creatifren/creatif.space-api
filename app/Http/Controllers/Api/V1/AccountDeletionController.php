<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Support\AccountDeletion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Settings → Account → Delete account (Play Store requirement), and its
 * undo within the grace period. See AccountDeletion.
 */
class AccountDeletionController extends Controller
{
    /**
     * DELETE /api/v1/me {confirm: "DELETE"}. 202 with purge_at, and the
     * Play manage URL when the plan is billed through Google Play (we do not
     * cancel it server-side; the app sends the person there).
     */
    public function destroy(Request $request): JsonResponse
    {
        $request->validate([
            'confirm' => ['required', 'string', Rule::in([AccountDeletion::CONFIRM])],
        ], [
            'confirm.*' => 'Type DELETE to confirm.',
        ]);

        $result = AccountDeletion::request($request->user());

        // Signed out here too: every session was revoked.
        if ($request->hasSession()) {
            auth('web')->logout();
            $request->session()->invalidate();
        }

        return response()->json(['data' => [
            'status' => UserStatus::PendingDeletion->value,
            'purge_at' => $result['purge_at'],
            'play_manage_url' => $result['play_manage_url'],
        ]], 202);
    }

    /** POST /api/v1/me/deletion/cancel — only while pending. */
    public function cancel(Request $request): JsonResponse
    {
        $user = $request->user()->refresh();
        if ($user->status !== UserStatus::PendingDeletion) {
            return response()->json(['message' => 'This account is not scheduled for deletion.'], 409);
        }

        AccountDeletion::cancel($user);

        return response()->json(['data' => ['status' => UserStatus::Active->value]]);
    }
}
