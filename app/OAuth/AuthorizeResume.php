<?php

namespace App\OAuth;

/**
 * A signed, short-lived copy of an /oauth/authorize request that survives
 * the session.
 *
 * The pending request (client_id, redirect_uri, state, PKCE challenge,
 * scope) otherwise lives only in the session, and signing out, "Use another
 * account" or the frontend's own sign-out invalidates the session — the
 * person then signed in again and landed on "expired". This token travels
 * in the login's ?next= (/oauth/resume?r=…) and in the consent screen's
 * forms, so the same request can always be shown again, to whoever is
 * signed in by then.
 *
 * It is only ever an /oauth/authorize URL on this host, and it grants
 * nothing: opening it shows the consent screen (or the login) again, and
 * Allow there still needs that screen's own one-time token and CSRF.
 */
final class AuthorizeResume
{
    public const TTL_SECONDS = 3600;

    /** The token for this authorize URL, or null if it is not one. */
    public static function token(string $authorizeUrl, ?int $now = null): ?string
    {
        $prefix = self::prefix();
        if (! str_starts_with($authorizeUrl, $prefix) || strlen($authorizeUrl) > 4000) {
            return null;
        }

        $payload = rtrim(strtr(base64_encode(substr($authorizeUrl, strlen($prefix))), '+/', '-_'), '=');
        $expires = ($now ?? time()) + self::TTL_SECONDS;

        return $payload.'.'.$expires.'.'.self::sign($payload, $expires);
    }

    /** The authorize URL a token stands for, or null if it is forged, malformed or expired. */
    public static function url(mixed $token, ?int $now = null): ?string
    {
        if (! is_string($token) || strlen($token) > 6000) {
            return null;
        }
        $parts = explode('.', $token);
        if (count($parts) !== 3 || ! ctype_digit($parts[1])) {
            return null;
        }
        [$payload, $expires, $signature] = $parts;
        if (! hash_equals(self::sign($payload, (int) $expires), $signature) || (int) $expires < ($now ?? time())) {
            return null;
        }

        $query = base64_decode(strtr($payload, '-_', '+/'), true);
        if (! is_string($query) || $query === '' || preg_match('/[\x00-\x20\x7F#]/', $query) === 1) {
            return null;
        }

        return self::prefix().$query;
    }

    /** Where the login sends the person back to: a path on our frontend, see SafePath. */
    public static function resumePath(string $authorizeUrl): string
    {
        $token = self::token($authorizeUrl);

        return $token === null ? '/oauth/resume' : '/oauth/resume?'.http_build_query(['r' => $token]);
    }

    private static function prefix(): string
    {
        return url('/oauth/authorize').'?';
    }

    private static function sign(string $payload, int $expires): string
    {
        return hash_hmac('sha256', 'oauth-resume|'.$payload.'|'.$expires, (string) config('app.key'));
    }
}
