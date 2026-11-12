<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Directory reviewers: the one kind of account that signs in with a
 * staff-set password at POST /auth/reviewer/login — no email verification,
 * no magic link, no MFA, and the password does not expire. Every other
 * account signs in as before. The flag is only ever set by
 * `php artisan reviewer:grant`.
 */
final class DirectoryReviewer
{
    public const SUCCESS = 'success';

    public const REJECTED = 'rejected';

    public const SUSPENDED = 'suspended';

    public const THROTTLED = 'throttled';

    public const GRANTED = 'granted';

    public const REVOKED = 'revoked';

    /** Attempts per address per window, on top of the per-IP route throttle. */
    public const MAX_ATTEMPTS = 5;

    public const DECAY_SECONDS = 900;

    public static function emailHash(string $email): string
    {
        return hash('sha256', Str::lower(trim($email)));
    }

    /** One audit row. Never throws: an audit write must not decide a sign-in. */
    public static function audit(?User $user, string $email, ?string $ip, string $outcome): void
    {
        try {
            DB::table('directory_reviewer_logins')->insert([
                'user_id' => $user?->getKey(),
                'email_hash' => self::emailHash($email),
                'ip' => $ip,
                'outcome' => $outcome,
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
