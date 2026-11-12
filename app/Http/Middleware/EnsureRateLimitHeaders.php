<?php

namespace App\Http\Middleware;

use App\Support\RateLimitHeaders;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Safety net for the API: a 429 that left out X-RateLimit-Limit,
 * X-RateLimit-Remaining or Retry-After gets them. The limit is the route's
 * own throttle (throttle:N,M → N); the wait is the body's retry_after, else
 * a minute (0 as the limit when the route has no throttle). Sources that
 * know a different limit set it themselves (RateLimitHeaders), and this
 * never overwrites what they set — except Remaining, always 0 on a 429.
 *
 * A 401 carries X-RateLimit-Limit / -Remaining of the refused-sign-in
 * limit below.
 */
class EnsureRateLimitHeaders
{
    /** Refused sign-ins (401) one address may make a minute with one credential (or none). */
    public const UNAUTHENTICATED_PER_MINUTE = 20;

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Blackbox B5: a 401 carried no limit at all. Refused sign-ins are
        // counted per address and credential (a shared MCP host sends many
        // people's tokens; one person's expired token must not block the
        // rest). Past the limit, the refusal itself becomes a 429 with the
        // headers; a request that signs in is never held up by it.
        if ($response->getStatusCode() === 401) {
            $key = 'api-401|'.$request->ip().'|'.substr(hash('sha256', (string) $request->bearerToken()), 0, 16);
            RateLimiter::hit($key, 60);

            if (RateLimiter::tooManyAttempts($key, self::UNAUTHENTICATED_PER_MINUTE + 1)) {
                $retry = RateLimiter::availableIn($key);

                return RateLimitHeaders::apply(response()->json([
                    'message' => "Too many attempts. Try again in {$retry} seconds.",
                    'retry_after' => $retry,
                ], 429), self::UNAUTHENTICATED_PER_MINUTE, $retry);
            }

            $response->headers->set('X-RateLimit-Limit', (string) self::UNAUTHENTICATED_PER_MINUTE);
            $response->headers->set('X-RateLimit-Remaining', (string) max(0, self::UNAUTHENTICATED_PER_MINUTE - RateLimiter::attempts($key)));

            return $response;
        }

        if ($response->getStatusCode() !== 429) {
            return $response;
        }

        $retry = $response->headers->get('Retry-After');
        if ($retry === null && $response instanceof JsonResponse) {
            $body = $response->getData(true);
            $retry = is_array($body) && is_numeric($body['retry_after'] ?? null) ? $body['retry_after'] : null;
        }

        RateLimitHeaders::apply($response, self::routeLimit($request), (int) ($retry ?? 60));
        // A 429 means nothing is left, whatever the route throttle counted
        // on the way out (it adds its own remaining to a hand-made 429).
        $response->headers->set('X-RateLimit-Remaining', '0');

        return $response;
    }

    /** N of the route's throttle:N,M, or 0 when the route has none. */
    private static function routeLimit(Request $request): int
    {
        $route = $request->route();
        if (! $route instanceof Route) {
            return 0;
        }
        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && preg_match('/^throttle:(\d+)/', $middleware, $m) === 1) {
                return (int) $m[1];
            }
        }

        return 0;
    }
}
