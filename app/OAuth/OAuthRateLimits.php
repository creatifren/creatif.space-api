<?php

namespace App\OAuth;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Passport\Client;
use Throwable;

/**
 * Rate limits for the OAuth endpoints MCP clients call.
 *
 * Claude and ChatGPT call /oauth/token and /oauth/register from a few
 * shared server addresses on behalf of many users, so a per-IP limit
 * alone would let one busy client throttle everyone on that platform.
 * The token endpoint is therefore limited per client_id + IP, with a wider
 * per-IP ceiling on top; registration and the browser-facing authorize
 * page per IP. Refusals (400 / 401) on all three are also counted per IP
 * (OAuthFailureLimit). Limits come from config('mcp.oauth_rate_limits')
 * (per minute).
 */
final class OAuthRateLimits
{
    public const TOKEN = 'oauth-token';

    public const REGISTER = 'oauth-register';

    public const AUTHORIZE = 'oauth-authorize';

    public const REVOKE = 'oauth-revoke';

    public static function register(): void
    {
        RateLimiter::for(self::TOKEN, function (Request $r) {
            $limits = [
                Limit::perMinute(self::limit('token', 120))->by('token|'.self::client($r).'|'.$r->ip()),
                // Every client from one address together: a made-up client_id
                // per request no longer escapes the limit.
                Limit::perMinute(self::limit('token_ip', 600))->by('token-ip|'.$r->ip()),
            ];
            // A client_id that names no client (client_id=x, a typo, a
            // probe) shares one small budget per address: blackbox B5
            // retest saw 25 such requests all answered 401.
            if (! self::knownClient($r)) {
                $limits[] = Limit::perMinute(self::failures())->by('token-unknown|'.$r->ip());
            }

            return $limits;
        });

        RateLimiter::for(self::REVOKE, fn (Request $r) => Limit::perMinute(self::limit('revoke', 60))
            ->by('revoke|'.self::client($r).'|'.$r->ip()));

        RateLimiter::for(self::REGISTER, fn (Request $r) => Limit::perMinute(self::limit('register', 30))
            ->by('register|'.$r->ip()));

        RateLimiter::for(self::AUTHORIZE, fn (Request $r) => Limit::perMinute(self::limit('authorize', 60))
            ->by('authorize|'.$r->ip()));
    }

    /** Refused OAuth requests (400 / 401) one address may make a minute. */
    public static function failures(): int
    {
        return self::limit('failures', 20);
    }

    private static function limit(string $key, int $default): int
    {
        return max(1, (int) config("mcp.oauth_rate_limits.{$key}", $default));
    }

    /**
     * Whether client_id names a registered client, or is a client metadata
     * document URL (resolved to a client further on). Missing counts as
     * unknown.
     */
    private static function knownClient(Request $r): bool
    {
        $id = $r->input('client_id');
        if (! is_string($id) || $id === '') {
            return false;
        }
        if (ClientMetadataDocuments::isUrl($id)) {
            return true;
        }

        try {
            return Client::query()->whereKey($id)->exists();
        } catch (Throwable) {
            return false;
        }
    }

    /** The client the request is for (hashed: a metadata-document client_id is a long URL). */
    private static function client(Request $r): string
    {
        $id = $r->input('client_id');

        return is_string($id) && $id !== '' ? substr(hash('sha256', $id), 0, 16) : '-';
    }
}
