<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blackbox B13: every answer from api.creatif.space carries
 * Strict-Transport-Security (one year, subdomains too) and
 * X-Content-Type-Options: nosniff.
 *
 * The app is the single source of HSTS: the value is set here, replacing
 * any a route set, and nginx must not add its own (the retest saw two
 * headers, nginx's without includeSubDomains).
 */
class SecurityHeaders
{
    public const HSTS = 'max-age=31536000; includeSubDomains';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('Strict-Transport-Security', self::HSTS);
        if (! $response->headers->has('X-Content-Type-Options')) {
            $response->headers->set('X-Content-Type-Options', 'nosniff');
        }

        return $response;
    }
}
