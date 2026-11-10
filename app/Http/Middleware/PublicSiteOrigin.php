<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * For endpoints only the marketing site may call from a browser (the
 * waitlist form): a request carrying an Origin other than PUBLIC_URL's is
 * refused. No Origin (curl, a server) passes; the throttle still applies.
 */
class PublicSiteOrigin
{
    public function handle(Request $request, Closure $next): Response
    {
        $origin = $request->headers->get('Origin');
        $allowed = rtrim((string) config('app.public_url'), '/');

        if ($origin !== null && rtrim($origin, '/') !== $allowed) {
            return response()->json(['message' => 'Origin not allowed.'], 403);
        }

        // The CORS headers themselves come from config/cors.php (PUBLIC_URL is listed).
        return $next($request);
    }
}
