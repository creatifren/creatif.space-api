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
 *
 * Cloudflare: when the request reaches us from one of Cloudflare's
 * published ranges (directly, or through a trusted proxy above), the
 * visitor is CF-Connecting-IP — Cloudflare sets it and a visitor cannot.
 * Without this every per-IP throttle keys on whichever Cloudflare edge
 * carried the request, so one caller spread over many edges is never
 * limited (blackbox B5). The header from anywhere else is ignored.
 * config('app.cloudflare_proxy') turns it off.
 */
class TrustAppServer
{
    public function handle(Request $request, Closure $next): Response
    {
        $request::setTrustedProxies(self::proxies(), Request::HEADER_X_FORWARDED_FOR);

        self::fromCloudflare($request);

        return $next($request);
    }

    /**
     * Cloudflare's published edge ranges (https://www.cloudflare.com/ips/).
     *
     * @var list<string>
     */
    public const CLOUDFLARE = [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
        '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
        '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
        '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
        '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
    ];

    /** The visitor behind a Cloudflare edge becomes $request->ip(). */
    private static function fromCloudflare(Request $request): void
    {
        if (! config('app.cloudflare_proxy', true)) {
            return;
        }

        $edge = $request->ip();
        $visitor = trim((string) $request->headers->get('CF-Connecting-IP', ''));

        if ($edge === null || $visitor === '' || filter_var($visitor, FILTER_VALIDATE_IP) === false
            || ! IpUtils::checkIp($edge, self::CLOUDFLARE)) {
            return;
        }

        // The visitor is now the socket address; forwarded hops before the
        // edge are the visitor's own word and are dropped.
        $request->server->set('REMOTE_ADDR', $visitor);
        $request->server->remove('HTTP_X_FORWARDED_FOR');
        $request->headers->remove('X-Forwarded-For');
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
