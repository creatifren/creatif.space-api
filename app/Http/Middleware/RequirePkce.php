<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * OAuth 2.1: every authorization request carries a PKCE S256 challenge,
 * confidential client or not. League only insists for public clients, and
 * still accepts `plain`; this closes both before the consent screen.
 */
class RequirePkce
{
    public function handle(Request $request, Closure $next): Response
    {
        $challenge = $request->query('code_challenge');
        $method = $request->query('code_challenge_method');

        if (! is_string($challenge) || $challenge === '' || $method !== 'S256') {
            return response()->json([
                'error' => 'invalid_request',
                'error_description' => 'PKCE is required: send code_challenge with code_challenge_method=S256.',
            ], 400);
        }

        return $next($request);
    }
}
