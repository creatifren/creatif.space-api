<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticate if a session is there; carry on if not.
 *
 * For public routes that stay public but want to know who you are when
 * they can — the File Request drop-box records the sender's account so the
 * submission turns up under "Asked of you", and behaves exactly the same
 * for somebody with no account at all.
 *
 * It never rejects, so adding it to a route can never take access away.
 */
class OptionalAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::guard('web')->check()) {
            $request->setUserResolver(fn () => Auth::guard('web')->user());
        }

        return $next($request);
    }
}
