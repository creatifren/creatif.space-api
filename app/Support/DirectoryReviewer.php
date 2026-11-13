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
 * account signs in as before. The flag is only ever set by staff, through
 * grant() (`php artisan reviewer:grant` and `reviewer:setup`).
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

    /** The reviewer password's length rule (reviewer:grant, and a reviewer's password reset). */
    public const MIN_PASSWORD = 12;

    public const MAX_PASSWORD = 72;

    /**
     * Make an existing account a reviewer: the flag, a verified address
     * (staff vouch for it), signed out everywhere. With a password it is
     * set too; without one the password is left exactly as it is — never
     * generated here.
     */
    public static function grant(User $user, string $email, ?string $password = null): void
    {
        $user->forceFill([
            'is_directory_reviewer' => true,
            ...($password !== null ? ['password' => $password] : []),
            'email_verified_at' => $user->email_verified_at ?? now(),
            'remember_token' => Str::random(60),
        ])->save();
        self::audit($user, $email, null, self::GRANTED);
    }

    /** No longer a reviewer: the flag and the password go, every session ends. */
    public static function revoke(User $user, string $email): void
    {
        $user->forceFill(['is_directory_reviewer' => false, 'password' => null, 'remember_token' => Str::random(60)])->save();
        self::audit($user, $email, null, self::REVOKED);
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
