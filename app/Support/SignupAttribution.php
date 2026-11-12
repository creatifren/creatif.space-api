<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * First-touch attribution kept on the user row (users.signup_attribution),
 * written once, when the account is created, and never overwritten.
 *
 * What the app sends with a sign-up (Google redirect query, email register
 * body, native sign-in body): utm_* (ProductEvents::UTM), `ref`, `entry`,
 * and the first landing's `referrer_host` and `landing_path`. Only the
 * fields below are stored; the referral code itself lives in referrals.
 *
 * Private: the column is hidden from serialization and appears in no API
 * resource. Support reads it with `php artisan support:user`.
 */
final class SignupAttribution
{
    /** The keys stored, in this order. */
    public const KEYS = ['utm_source', 'utm_medium', 'utm_campaign', 'referrer_host', 'landing_path', 'signup_source'];

    /** A bare hostname: no scheme, port, path or credentials. */
    public const HOST_PATTERN = '/^(?=.{1,253}$)[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$/';

    /** A path only: no query, no fragment, no scheme, not scheme-relative (//host). */
    public const PATH_PATTERN = '#^/(?!/)[A-Za-z0-9/_.~%-]{0,199}$#';

    /** Before this change nobody's source was recorded. */
    public const UNKNOWN = 'unknown';

    /** A valid referrer host, lower-cased, else null (dropped, never a 422). */
    public static function host(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $host = strtolower(trim($value));

        return preg_match(self::HOST_PATTERN, $host) === 1 ? $host : null;
    }

    /** A valid landing path, else null. */
    public static function path(mixed $value): ?string
    {
        return is_string($value) && preg_match(self::PATH_PATTERN, $value) === 1 ? $value : null;
    }

    /**
     * referrer_host and landing_path from a request's query/body (the valid ones).
     *
     * @return array<string, string>
     */
    public static function touchFrom(Request $request): array
    {
        $out = [];
        $host = self::host($request->input('referrer_host'));
        if ($host !== null) {
            $out['referrer_host'] = $host;
        }
        $path = self::path($request->input('landing_path'));
        if ($path !== null) {
            $out['landing_path'] = $path;
        }

        return $out;
    }

    /**
     * What a signup carries, as stored.
     *
     * @param  array<string, mixed>  $attribution  utm_*, ref, entry, referrer_host, landing_path
     * @return array<string, string>
     */
    public static function of(array $attribution): array
    {
        $out = [];
        foreach (['utm_source', 'utm_medium', 'utm_campaign'] as $key) {
            $value = $attribution[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $out[$key] = substr(trim($value), 0, 100);
            }
        }
        $host = self::host($attribution['referrer_host'] ?? null);
        if ($host !== null) {
            $out['referrer_host'] = $host;
        }
        $path = self::path($attribution['landing_path'] ?? null);
        if ($path !== null) {
            $out['landing_path'] = $path;
        }
        $out['signup_source'] = ProductEvents::signupSource($attribution);

        return $out;
    }

    /**
     * Keep $attribution on a new account. First touch wins: a row that
     * already has one is left as it is.
     *
     * @param  array<string, mixed>  $attribution
     */
    public static function store(User $user, array $attribution): void
    {
        if ($user->getAttributes()['signup_attribution'] ?? null) {
            return;
        }

        $user->forceFill(['signup_attribution' => self::of($attribution)])->save();
    }

    /** The user's signup_source, or "unknown" for accounts made before it was kept. */
    public static function sourceOf(User $user): string
    {
        $stored = $user->signup_attribution;

        return is_array($stored) && is_string($stored['signup_source'] ?? null) ? $stored['signup_source'] : self::UNKNOWN;
    }
}
