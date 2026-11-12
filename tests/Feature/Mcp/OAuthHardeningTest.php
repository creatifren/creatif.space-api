<?php

/**
 * OAuth endpoint hardening (directory item 7): rate limits on token,
 * register, authorize and revoke, keyed so one busy client on a shared
 * platform IP does not throttle another; every refusal on an OAuth endpoint
 * is OAuth-shaped JSON. Scopes are unchanged.
 */
beforeEach(function () {
    ensurePassportKeys();
    config(['app.url' => 'https://api.creatif.space', 'mcp.oauth_rate_limits' => ['token' => 2, 'revoke' => 2, 'register' => 2, 'authorize' => 2]]);
});

function tokenCall($test, string $clientId)
{
    return $test->post('/oauth/token', ['grant_type' => 'refresh_token', 'client_id' => $clientId, 'refresh_token' => 'garbage']);
}

it('limits the token endpoint per client, answering an OAuth-shaped 429 with Retry-After', function () {
    $a = mcpClient();
    $b = mcpClient('https://other.test/cb');

    tokenCall($this, $a->getKey())->assertStatus(400);
    tokenCall($this, $a->getKey())->assertStatus(400);
    tokenCall($this, $a->getKey())
        ->assertStatus(429)
        ->assertHeader('Retry-After')
        ->assertJsonPath('error', 'temporarily_unavailable')
        ->assertJsonStructure(['error', 'error_description']);

    // Another client from the same address is not throttled by the first.
    tokenCall($this, $b->getKey())->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
});

it('limits Dynamic Client Registration per address, with the same 429 shape', function () {
    $body = ['client_name' => 'Claude', 'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback']];

    $this->postJson('/oauth/register', $body)->assertCreated();
    $this->postJson('/oauth/register', $body)->assertCreated();
    $this->postJson('/oauth/register', $body)
        ->assertStatus(429)
        ->assertHeader('Retry-After')
        ->assertJsonPath('error', 'temporarily_unavailable')
        ->assertJsonStructure(['error', 'error_description']);
});

it('limits the authorize page per address, with the same 429 shape', function () {
    $client = mcpClient();
    [, $challenge] = pkcePair();
    $query = '/oauth/authorize?'.http_build_query([
        'client_id' => $client->getKey(), 'redirect_uri' => $client->redirect_uris[0], 'response_type' => 'code',
        'scope' => 'read', 'state' => 's', 'code_challenge' => $challenge, 'code_challenge_method' => 'S256',
    ]);

    $this->get($query);
    $this->get($query);
    $this->get($query)->assertStatus(429)->assertJsonPath('error', 'temporarily_unavailable');
});

it('limits revocation per client', function () {
    $client = mcpClient();

    $this->post('/oauth/revoke', ['client_id' => $client->getKey(), 'token' => 'x'])->assertOk();
    $this->post('/oauth/revoke', ['client_id' => $client->getKey(), 'token' => 'x'])->assertOk();
    $this->post('/oauth/revoke', ['client_id' => $client->getKey(), 'token' => 'x'])
        ->assertStatus(429)->assertJsonPath('error', 'temporarily_unavailable');
});

it('answers a wrong method on an OAuth endpoint with OAuth-shaped JSON', function (string $method, string $path) {
    $this->call($method, $path)
        ->assertStatus(405)
        ->assertJsonPath('error', 'invalid_request')
        ->assertJsonStructure(['error', 'error_description']);
})->with([
    ['GET', '/oauth/token'],
    ['GET', '/oauth/register'],
    ['GET', '/oauth/revoke'],
]);

it('keeps Passport\'s own OAuth errors as they are', function () {
    tokenCall($this, mcpClient()->getKey())->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
    $this->post('/oauth/token', ['grant_type' => 'password'])->assertStatus(400)->assertJsonStructure(['error', 'error_description']);
});

it('ships the documented default limits', function () {
    $defaults = require base_path('config/mcp.php');

    expect($defaults['oauth_rate_limits'])->toBe(['token' => 120, 'revoke' => 60, 'register' => 30, 'authorize' => 60, 'token_ip' => 600, 'failures' => 20]);
});
