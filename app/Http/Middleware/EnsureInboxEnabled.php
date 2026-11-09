<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** INBOX_ENABLED=false hides the whole /inbox surface. */
class EnsureInboxEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('services.inbox.enabled')) {
            return response()->json(['message' => 'The Inbox is not enabled.', 'code' => 'inbox_disabled'], 404);
        }

        return $next($request);
    }
}
