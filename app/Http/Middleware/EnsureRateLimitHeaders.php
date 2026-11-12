<?php

namespace App\Http\Middleware;

use App\Support\RateLimitHeaders;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * Safety net for the API: a 429 that left out X-RateLimit-Limit,
 * X-RateLimit-Remaining or Retry-After gets them. The limit is the route's
 * own throttle (throttle:N,M → N); the wait is the body's retry_after, else
 * a minute (0 as the limit when the route has no throttle). Sources that
 * know a different limit set it themselves (RateLimitHeaders), and this
 * never overwrites what they set — except Remaining, always 0 on a 429.
 */
class EnsureRateLimitHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

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
