<?php

namespace App\Http\Controllers\OAuth;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Discovery documents MCP clients read before anything else.
 *
 * - RFC 8414 authorization server metadata: where to register, authorize,
 *   exchange and revoke, and what is supported (code + PKCE S256 only).
 * - RFC 9728 protected resource metadata for the MCP server
 *   (https://mcp.creatif.space/mcp): which authorization server issues
 *   its tokens, and with which scopes. The MCP server serves the same
 *   document on its own origin; this copy is the source of truth.
 */
class MetadataController extends Controller
{
    public function authorizationServer(): JsonResponse
    {
        $issuer = $this->issuer();

        return $this->cached([
            'issuer' => $issuer,
            'authorization_endpoint' => $issuer.'/oauth/authorize',
            'token_endpoint' => $issuer.'/oauth/token',
            'registration_endpoint' => $issuer.'/oauth/register',
            'revocation_endpoint' => $issuer.'/oauth/revoke',
            'introspection_endpoint' => $issuer.'/api/v1/oauth/introspect',
            'scopes_supported' => array_keys((array) config('mcp.scopes')),
            'response_types_supported' => ['code'],
            'response_modes_supported' => ['query'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            // MCP auth spec: a client_id may be an https URL to its metadata document.
            'client_id_metadata_document_supported' => true,
            'token_endpoint_auth_methods_supported' => ['none', 'client_secret_post', 'client_secret_basic'],
            'revocation_endpoint_auth_methods_supported' => ['none', 'client_secret_post', 'client_secret_basic'],

            'service_documentation' => 'https://creatif.space/docs/mcp',
            'op_policy_uri' => (string) config('mcp.privacy_url'),
            'op_tos_uri' => (string) config('mcp.terms_url'),
            'logo_uri' => $this->logoUri(),
        ]);
    }

    public function protectedResource(): JsonResponse
    {
        return $this->cached([
            'resource' => (string) config('mcp.resource_url'),
            'authorization_servers' => [$this->issuer()],
            'scopes_supported' => array_keys((array) config('mcp.scopes')),
            'bearer_methods_supported' => ['header'],
            'resource_name' => 'Creatif Space',
            'logo_uri' => $this->logoUri(),
            'resource_documentation' => 'https://creatif.space/docs/mcp',
            'resource_policy_uri' => (string) config('mcp.privacy_url'),
            'resource_tos_uri' => (string) config('mcp.terms_url'),
        ]);
    }

    /**
     * The connector's icon: the rocket mark the MCP server serves on its own
     * origin (mcp/public/icon.png), so Claude and ChatGPT show it, not an
     * old logo. Follows the MCP origin, so staging names its own.
     */
    private function logoUri(): string
    {
        $resource = (string) config('mcp.resource_url');
        $scheme = parse_url($resource, PHP_URL_SCHEME) ?: 'https';
        $host = parse_url($resource, PHP_URL_HOST) ?: 'mcp.creatif.space';
        $port = parse_url($resource, PHP_URL_PORT);

        return $scheme.'://'.$host.($port ? ':'.$port : '').'/icon.png';
    }

    private function issuer(): string
    {
        return rtrim((string) config('app.url'), '/');
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function cached(array $document): JsonResponse
    {
        return response()
            ->json($document, 200, [], JSON_UNESCAPED_SLASHES)
            ->header('Cache-Control', 'public, max-age=3600')
            ->header('Access-Control-Allow-Origin', '*');
    }
}
