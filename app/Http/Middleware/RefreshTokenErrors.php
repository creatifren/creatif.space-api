<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST /oauth/token with grant_type=refresh_token: a refresh token that is
 * dead — rotated out, revoked, expired, unknown or unreadable — is always
 * `invalid_grant` with 400 (RFC 6749 §5.2), which is what clients such as
 * Claude and ChatGPT key their "sign in again" on. League answers most of
 * these that way already; this pins it for every case.
 */
class RefreshTokenErrors
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->input('grant_type') !== 'refresh_token' || $response->getStatusCode() < 400 || $response->getStatusCode() >= 500) {
            return $response;
        }

        $body = json_decode((string) $response->getContent(), true);
        if (! is_array($body) || ! in_array($body['error'] ?? null, ['invalid_request', 'invalid_grant'], true)) {
            return $response;
        }

        // A missing refresh_token parameter stays invalid_request.
        $token = $request->input('refresh_token');
        if (! is_string($token) || $token === '') {
            return $response;
        }

        return new JsonResponse([
            'error' => 'invalid_grant',
            'error_description' => 'The refresh token is invalid, expired or revoked. Sign in again.',
        ], 400, ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
    }
}
