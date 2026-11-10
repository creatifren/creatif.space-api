<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * For endpoints only the marketing site may call from a browser (the
 * waitlist form): a request carrying an Origin other than MARKETING_URL's
 * (https://creatif.space) is refused — the app (app.creatif.space)
 * included. No Origin (curl, a server) passes; the throttle still applies.
 */
class MarketingSiteOrigin
{
    public function handle(Request $request, Closure $next): Response
    {
        $origin = $request->headers->get('Origin');
        $allowed = rtrim((string) config('app.marketing_url'), '/');

        if ($origin !== null && rtrim($origin, '/') !== $allowed) {
            return response()->json(['message' => 'Origin not allowed.'], 403);
        }

        // The CORS headers themselves come from config/cors.php (MARKETING_URL is listed).
        return $next($request);
    }
}
