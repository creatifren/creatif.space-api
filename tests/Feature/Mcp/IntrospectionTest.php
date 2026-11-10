<?php

use App\Models\User;
use Laravel\Passport\Token;
use Tests\TestCase;

beforeEach(function () {
    ensurePassportKeys();
    config(['mcp.introspection_secret' => 'shh-test-secret']);
});

function introspect(TestCase $test, ?string $token, ?string $secret = 'shh-test-secret')
{
    app('auth')->forgetGuards();

    return $test->withHeaders($secret === null ? [] : ['X-MCP-Secret' => $secret])
        ->postJson('/api/v1/oauth/introspect', ['token' => $token]);
}

it('requires the shared secret', function (?string $secret) {
    introspect($this, 'whatever', $secret)->assertUnauthorized()->assertJsonMissingPath('active');
})->with([null, 'wrong']);

it('is closed when no secret is configured', function () {
    config(['mcp.introspection_secret' => '']);

    introspect($this, 'whatever', '')->assertUnauthorized();
});

it('describes a live OAuth access token', function () {
    $user = User::factory()->create();
    $client = mcpClient();
    $tokens = oauthTokens($this, $user, $client, 'read draft');

    $response = introspect($this, $tokens['access_token'])
        ->assertOk()
        ->assertExactJson([
            'active' => true,
            'user_ulid' => $user->ulid,
            'scopes' => ['read', 'draft'],
            'client_id' => (string) $client->getKey(),
            'client_name' => 'Claude',
            'token_type' => 'oauth',
            'exp' => Token::query()->sole()->expires_at->getTimestamp(),
        ]);

    expect($response->json('exp'))->toBeGreaterThan(time());
});

it('describes a live API key', function () {
    $user = User::factory()->create();
    $key = $this->actingAs($user, 'web')
        ->postJson('/api/v1/me/api-keys', ['name' => 'Claude Code', 'scopes' => ['read']])
        ->json('data.key');

    introspect($this, $key)
        ->assertOk()
        ->assertExactJson([
            'active' => true,
            'user_ulid' => $user->ulid,
            'scopes' => ['read'],
            'client_id' => null,
            'client_name' => 'Claude Code',
            'token_type' => 'api_key',
            'exp' => null,
        ]);
});

it('answers inactive for anything that does not resolve', function (?string $token) {
    introspect($this, $token)->assertOk()->assertExactJson(['active' => false]);
})->with([null, '', 'garbage', 'cs_live_nope', 'a.b.c']);

it('answers inactive for a revoked token', function () {
    $client = mcpClient();
    $tokens = oauthTokens($this, User::factory()->create(), $client);
    $this->post('/oauth/revoke', ['client_id' => $client->getKey(), 'token' => $tokens['access_token']]);

    introspect($this, $tokens['access_token'])->assertExactJson(['active' => false]);
});
