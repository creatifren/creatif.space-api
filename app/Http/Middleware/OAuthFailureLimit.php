<?php

namespace App\Http\Middleware;

use App\OAuth\OAuthRateLimits;
use App\Support\RateLimitHeaders;
use Closure;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blackbox B5: /oauth/token answered 25 bad requests in a row with 400 and
 * never a 429 — the per-client limit counts each made-up client_id on its
 * own. Here the refusals themselves (400 / 401) are counted per address
 * across /oauth/token, /oauth/register and /oauth/authorize; past
 * OAuthRateLimits::failures() a minute, the address gets the OAuth-shaped
 * 429 (bootstrap/app.php) with Retry-After and X-RateLimit-* headers
 * instead of the refusal. Only refusals turn into 429s: Claude and ChatGPT
 * call from shared addresses, and a successful request is never held up
 * by someone else's failures.
 */
class OAuthFailureLimit
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (in_array($response->getStatusCode(), [400, 401], true)) {
            $key = 'oauth-failures|'.$request->ip();
            $max = OAuthRateLimits::failures();
            RateLimiter::hit($key, 60);

            if (RateLimiter::tooManyAttempts($key, $max + 1)) {
                throw new ThrottleRequestsException('Too Many Attempts.', null, RateLimitHeaders::for($max, RateLimiter::availableIn($key)));
            }
        }

        return $response;
    }
}
