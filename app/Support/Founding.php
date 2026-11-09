<?php

namespace App\Support;

/**
 * "Founding 100": 30% off the first year for the first 100 customers on a
 * yearly plan. The limit lives in Creem (the code's max redemptions); we
 * only send the code on yearly checkouts and drop it if Creem refuses it.
 */
class Founding
{
    public static function code(): ?string
    {
        $code = config('services.creem.founding_code');

        return is_string($code) && $code !== '' ? $code : null;
    }

    public static function active(): bool
    {
        return self::code() !== null;
    }

    public static function percent(): int
    {
        return (int) config('services.creem.founding_percent', 30);
    }

    /** The code for this checkout: yearly only, never monthly. */
    public static function codeFor(string $period): ?string
    {
        return $period === 'yearly' ? self::code() : null;
    }
}
