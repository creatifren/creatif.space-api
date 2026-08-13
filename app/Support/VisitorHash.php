<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Who a visitor is, for one day only.
 *
 * The salt is derived from today's date and the app key, so the same
 * browser hashes differently tomorrow. That is the privacy guarantee and
 * the definition at once: "unique visitors" means per browser, per day,
 * which is exactly what the dashboard claims to count. Nobody can join two
 * days of traffic back into one person, including us.
 *
 * No new secret, and no salt table to remember or prune.
 */
final class VisitorHash
{
    public static function for(Request $request): string
    {
        return self::of(
            $request->ip() ?? '',
            (string) $request->userAgent(),
        );
    }

    /**
     * The same hash, from parts — so tests can ask what yesterday's would
     * have been without faking a request.
     */
    public static function of(string $ip, string $userAgent, ?string $date = null): string
    {
        $salt = hash_hmac(
            'sha256',
            $date ?? now()->toDateString(),
            (string) config('app.key'),
        );

        return sha1($ip.'|'.$userAgent.'|'.$salt);
    }
}
