<?php

use App\Models\User;

/*
 * The protected resource is the exact connector URL, https://mcp.creatif.space/mcp,
 * on both sides: the Node server's MCP_RESOURCE_URL and this config's default.
 * Claude sends it as the RFC 8707 `resource` parameter on authorize and token.
 */

beforeEach(function () {
    ensurePassportKeys();
    config(['mcp.resource_url' => 'https://mcp.creatif.space/mcp']);
    config(['mcp.introspection_secret' => 'test-secret']);
});

it('defaults the resource to the exact connector URL', function () {
    // The code default, independent of whatever .env the run has.
    expect(file_get_contents(base_path('config/mcp.php')))
        ->toContain("env('MCP_RESOURCE_URL', 'https://mcp.creatif.space/mcp')")
        ->and(file_get_contents(base_path('.env.example')))
        ->toContain('MCP_RESOURCE_URL=https://mcp.creatif.space/mcp');
});

it('advertises it at the root and the RFC 9728 path form', function (string $path) {
    $this->getJson($path)
        ->assertOk()
        ->assertJsonPath('resource', 'https://mcp.creatif.space/mcp')
        ->assertJsonPath('scopes_supported', ['read', 'draft', 'approve', 'publish']);
})->with([
    '/.well-known/oauth-protected-resource',
    '/.well-known/oauth-protected-resource/mcp',
]);

it('issues a token for resource=/mcp that introspects as active', function () {
    $resource = 'https://mcp.creatif.space/mcp';
    $user = User::factory()->create();
    $client = mcpClient();
    [$verifier, $challenge] = pkcePair();
    $redirect = $client->redirect_uris[0];

    // What Claude sends: the usual PKCE flow plus `resource` on both legs.
    $this->actingAs($user, 'web')
        ->get('/oauth/authorize?'.http_build_query([
            'client_id' => $client->getKey(),
            'redirect_uri' => $redirect,
            'response_type' => 'code',
            'scope' => 'read draft',
            'state' => 'st',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'resource' => $resource,
        ]))
        ->assertOk();

    $approve = $this->post('/oauth/authorize', [
        'state' => 'st',
        'client_id' => $client->getKey(),
        'auth_token' => session('authToken'),
    ])->assertRedirect();
    parse_str((string) parse_url((string) $approve->headers->get('Location'), PHP_URL_QUERY), $query);

    $tokens = $this->post('/oauth/token', [
        'grant_type' => 'authorization_code',
        'client_id' => $client->getKey(),
        'redirect_uri' => $redirect,
        'code_verifier' => $verifier,
        'code' => $query['code'],
        'resource' => $resource,
    ])->assertOk()->json();

    app('auth')->forgetGuards();
    $this->flushSession();

    $this->withHeader('X-MCP-Secret', 'test-secret')
        ->postJson('/api/v1/oauth/introspect', ['token' => $tokens['access_token']])
        ->assertOk()
        ->assertJsonPath('active', true)
        ->assertJsonPath('user_ulid', $user->ulid)
        ->assertJsonPath('scopes', ['read', 'draft'])
        ->assertJsonPath('token_type', 'oauth');
});
