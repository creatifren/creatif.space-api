<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\User;
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

    /**
     * GET /api/v1/me/deletion — where the account stands: active, or
     * pending with when it was asked, when the purge runs, the days left
     * and whether it can still be cancelled. Reachable while pending.
     */
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => self::status($request->user()->refresh())]);
    }

    /**
     * POST /api/v1/me/deletion/cancel — only while pending (409 otherwise,
     * and 409 once the purge date has passed). Access comes back; what the
     * request stopped (scheduled posts, approval links, connected channels,
     * the subscription) stays stopped.
     */
    public function cancel(Request $request): JsonResponse
    {
        $user = $request->user()->refresh();
        if ($user->status !== UserStatus::PendingDeletion) {
            return response()->json(['message' => 'This account is not scheduled for deletion.', 'reason' => 'not_pending'], 409);
        }
        $purgeAt = AccountDeletion::purgeAt($user);
        if ($purgeAt !== null && $purgeAt->isPast()) {
            return response()->json(['message' => 'The 30-day grace period has ended.', 'reason' => 'grace_ended'], 409);
        }

        AccountDeletion::cancel($user);

        return response()->json(['data' => self::status($user->refresh())]);
    }

    /**
     * @return array{status: string, requested_at: string|null, purge_at: string|null, grace_days: int, days_left: int|null, can_cancel: bool}
     */
    public static function status(User $user): array
    {
        $pending = $user->status === UserStatus::PendingDeletion;
        $purgeAt = $pending ? AccountDeletion::purgeAt($user) : null;

        return [
            'status' => $pending ? UserStatus::PendingDeletion->value : UserStatus::Active->value,
            'requested_at' => $pending ? $user->deletion_requested_at?->utc()->toIso8601String() : null,
            'purge_at' => $purgeAt?->utc()->toIso8601String(),
            'grace_days' => AccountDeletion::GRACE_DAYS,
            // Whole days until the purge, rounded up: "deleted in 3 days".
            'days_left' => $purgeAt === null ? null : max(0, (int) ceil(now()->diffInSeconds($purgeAt, false) / 86400)),
            'can_cancel' => $purgeAt !== null && $purgeAt->isFuture(),
        ];
    }
}
