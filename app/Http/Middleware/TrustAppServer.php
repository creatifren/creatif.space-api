<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Replaces Laravel's TrustProxies. Only the addresses in TRUSTED_PROXIES
 * (our app server; comma-separated IPs or CIDRs) may tell us the visitor's
 * IP, and only through X-Forwarded-For: Host, Proto, Port and Prefix are
 * never taken from a header.
 *
 * '*', '**', REMOTE_ADDR and anything that is not an IP or CIDR are
 * dropped, so a typo cannot open this to every caller. Empty (the default)
 * trusts no one: $request->ip() is then the socket address, as before.
 */
class TrustAppServer
{
    public function handle(Request $request, Closure $next): Response
    {
        $request::setTrustedProxies(self::proxies(), Request::HEADER_X_FORWARDED_FOR);

        return $next($request);
    }

    /** @return list<string> */
    public static function proxies(): array
    {
        $raw = (string) config('app.trusted_proxies', '');

        return array_values(array_filter(
            array_map(trim(...), explode(',', $raw)),
            fn (string $entry): bool => self::valid($entry),
        ));
    }

    private static function valid(string $entry): bool
    {
        if ($entry === '' || str_contains($entry, '*')) {
            return false;
        }

        [$ip, $bits] = array_pad(explode('/', $entry, 2), 2, null);
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        if ($bits === null) {
            return true;
        }

        $max = str_contains($ip, ':') ? 128 : 32;
        // No /0: that would be every address again.
        if (! ctype_digit($bits) || (int) $bits < 1 || (int) $bits > $max) {
            return false;
        }

        return IpUtils::checkIp($ip, $entry);
    }
}
