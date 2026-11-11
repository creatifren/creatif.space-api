<?php

namespace App\Support;

use App\Models\SocialApprovalLink;
use App\Notifications\ApproverSignInLink;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * One-time email sign-in for approvers (POST /a/{token}/email-link).
 *
 * - Sent only to the link's approver_email (or, on an old unbound link,
 *   to whoever asks: the first to decide binds it — same as Google).
 * - 15 minutes, single use; only the SHA-256 of the token is stored.
 * - Throttled per link + address (3 per 15 minutes) on top of the route's
 *   per-IP limit. A throttled or non-matching request sends nothing and
 *   answers exactly like a sent one.
 */
final class ApproverSignIn
{
    public const TTL_MINUTES = 15;

    public const PER_ADDRESS = 3;

    public static function send(SocialApprovalLink $link, string $email): bool
    {
        if ($link->approver_email !== null && $link->approver_email !== $email) {
            Log::info('approver.email_link_ignored', ['link' => $link->ulid, 'reason' => 'not_approver']);

            return false;
        }

        $key = 'approver-email-link:'.$link->id.':'.hash('sha256', $email);
        if (RateLimiter::tooManyAttempts($key, self::PER_ADDRESS)) {
            Log::info('approver.email_link_ignored', ['link' => $link->ulid, 'reason' => 'throttled']);

            return false;
        }
        RateLimiter::hit($key, self::TTL_MINUTES * 60);

        $token = Str::random(64);
        DB::table('approver_login_tokens')->insert([
            'social_approval_link_id' => $link->id,
            'email' => $email,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
            'created_at' => now(),
        ]);

        Notification::route('mail', $email)->notify(new ApproverSignInLink(
            $link,
            route('auth.approver-link', ['token' => $token]),
        ));

        return true;
    }

    /**
     * Use a token once: the link and the address it was issued for, or null
     * when it is unknown, used or expired. Atomic — two clicks of the same
     * email sign in once.
     *
     * @return array{link: SocialApprovalLink, email: string}|null
     */
    public static function consume(string $token): ?array
    {
        if ($token === '' || strlen($token) > 255) {
            return null;
        }

        $hash = hash('sha256', $token);
        $row = DB::table('approver_login_tokens')->where('token_hash', $hash)->first();
        if ($row === null) {
            return null;
        }

        $used = DB::table('approver_login_tokens')
            ->where('id', $row->id)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->update(['used_at' => now()]);

        $link = SocialApprovalLink::query()->find($row->social_approval_link_id);

        return $used === 1 && $link !== null ? ['link' => $link, 'email' => (string) $row->email] : null;
    }
}
