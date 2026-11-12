<?php

namespace App\Http\Controllers\OAuth;

use App\Http\Controllers\Controller;
use App\Mcp\McpTokenResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Token introspection for the MCP server (RFC 7662 in spirit; the field
 * names are the MCP server's contract).
 *
 *   POST /api/v1/oauth/introspect
 *   X-MCP-Secret: <MCP_INTROSPECT_SECRET>
 *   {"token": "<OAuth access token or cs_live_ API key>"}
 *
 *   → {active, user_ulid, scopes[], client_id, client_name, client_surface, token_type, exp}
 *
 * client_surface is 'chatgpt' for ChatGPT (by DCR name or redirect host),
 * else 'other': the MCP server words plan errors neutrally for ChatGPT.
 *
 * Anything that does not resolve — expired, revoked, unknown, a suspended
 * account — is `{"active": false}` and nothing more.
 */
class IntrospectionController extends Controller
{
    public function __invoke(Request $request, McpTokenResolver $resolver): JsonResponse
    {
        $secret = (string) config('mcp.introspection_secret');
        $given = $request->header('X-MCP-Secret');

        if ($secret === '' || ! is_string($given) || ! hash_equals($secret, $given)) {
            return response()->json(['message' => 'Unauthenticated.', 'error' => 'invalid_client'], 401, [
                'Cache-Control' => 'no-store',
            ]);
        }

        $token = $request->input('token');
        $context = is_string($token) && $token !== '' && strlen($token) < 8192
            ? $resolver->resolve($token)
            : null;

        if ($context === null) {
            return response()->json(['active' => false], 200, ['Cache-Control' => 'no-store']);
        }

        return response()->json([
            'active' => true,
            'user_ulid' => $context->user->ulid,
            'scopes' => $context->scopes,
            'client_id' => $context->clientId,
            'client_name' => $context->clientName,
            'client_surface' => $context->clientSurface,
            'token_type' => $context->kind,
            'exp' => $context->expiresAt,
        ], 200, ['Cache-Control' => 'no-store']);
    }
}
