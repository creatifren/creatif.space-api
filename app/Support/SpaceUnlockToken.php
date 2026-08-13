<?php

namespace App\Support;

use App\Models\Space;

/**
 * Stateless unlock token for password-protected Spaces. Anonymous clients
 * have no Laravel session, so a signed value is the state:
 * base64url("{ulid}.{expires}.{hmac}") with a 24h TTL.
 */
class SpaceUnlockToken
{
    private const TTL_HOURS = 24;

    public static function issue(Space $space): string
    {
        $expires = now()->addHours(self::TTL_HOURS)->getTimestamp();
        $signature = self::sign($space->ulid, $expires);

        return rtrim(strtr(base64_encode("{$space->ulid}.{$expires}.{$signature}"), '+/', '-_'), '=');
    }

    public static function verify(Space $space, ?string $token): bool
    {
        if ($token === null || $token === '') {
            return false;
        }

        $decoded = base64_decode(strtr($token, '-_', '+/'), true);
        if ($decoded === false) {
            return false;
        }

        $parts = explode('.', $decoded);
        if (count($parts) !== 3) {
            return false;
        }

        [$ulid, $expires, $signature] = $parts;

        return $ulid === $space->ulid
            && ctype_digit($expires)
            && (int) $expires > now()->getTimestamp()
            && hash_equals(self::sign($ulid, (int) $expires), $signature);
    }

    private static function sign(string $ulid, int $expires): string
    {
        return hash_hmac('sha256', "{$ulid}|{$expires}", (string) config('app.key'));
    }
}
