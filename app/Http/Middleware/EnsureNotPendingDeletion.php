<?php

namespace App\Http\Middleware;

use App\Enums\UserStatus;
use App\Support\AccountDeletion;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * An account waiting to be deleted can sign in and cancel, nothing else:
 * every other authenticated route answers 423 with the purge date, so the
 * app can show "Your account will be deleted on … — keep it?".
 */
class EnsureNotPendingDeletion
{
    /** Routes a pending account may still reach: who am I (with purge_at), and cancel. */
    public const ALLOWED = ['api.v1.me', 'api.v1.me.deletion.cancel', 'api.v1.me.destroy'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        // Read raw: a user built in memory may not carry the column default.
        $status = $user?->getAttributes()['status'] ?? null;
        $status = $status instanceof UserStatus ? $status->value : $status;

        if ($user !== null && $status === UserStatus::PendingDeletion->value
            && ! in_array($request->route()?->getName(), self::ALLOWED, true)) {
            return response()->json([
                'message' => 'This account is scheduled for deletion. Cancel the deletion to use it again.',
                'reason' => 'account_pending_deletion',
                'purge_at' => AccountDeletion::purgeAt($user)?->utc()->toIso8601String(),
            ], 423);
        }

        return $next($request);
    }
}
