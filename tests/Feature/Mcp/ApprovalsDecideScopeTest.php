<?php

use App\Models\User;
use Laravel\Passport\Client;
use Tests\TestCase;

/*
 * approvals:decide as a bug fix (CTO q1345): no new scope, no re-consent.
 * The owner's scopes come back exactly as granted; a token without the
 * grant is refused on the approver routes; a token granted the scope keeps
 * it — after a later reconnect of the same app, and through a refresh.
 */

beforeEach(function () {
    ensurePassportKeys();
    config(['mcp.introspection_secret' => 'shh-test-secret']);
});

function decideIntrospect(TestCase $test, string $token): array
{
    app('auth')->forgetGuards();

    return $test->withHeaders(['X-MCP-Secret' => 'shh-test-secret'])
        ->postJson('/api/v1/oauth/introspect', ['token' => $token])->assertOk()->json();
}

function decideRefresh(TestCase $test, Client $client, string $token): array
{
    app('auth')->forgetGuards();

    return $test->post('/oauth/token', [
        'grant_type' => 'refresh_token',
        'client_id' => $client->getKey(),
        'refresh_token' => $token,
    ])->assertOk()->json();
}

it('leaves the owner scopes unchanged, and refuses the approver routes without the grant', function () {
    $owner = User::factory()->create(['email_verified_at' => now()]);
    $tokens = oauthTokens($this, $owner, mcpClient(), 'read draft approve publish');

    expect(decideIntrospect($this, $tokens['access_token'])['scopes'])->toBe(['read', 'draft', 'approve', 'publish']);

    foreach (['/api/v1/me/approvals/waiting', '/api/v1/me/reports/shared'] as $path) {
        bearer($this, $tokens['access_token'])->getJson($path)
            ->assertForbidden()
            ->assertJsonPath('error', 'insufficient_scope')
            ->assertHeader('WWW-Authenticate', 'Bearer error="insufficient_scope", scope="approvals:decide"');
    }
});

it('a token granted approvals:decide keeps it after the same app is reconnected, and through a refresh', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $client = mcpClient();

    // Granted before the reconnect.
    $before = oauthTokens($this, $user, $client, 'read approvals:decide');
    // The reconnect: a new consent for the same app, without approvals:decide.
    $after = oauthTokens($this, $user, $client, 'read draft');

    expect(decideIntrospect($this, $before['access_token'])['scopes'])->toBe(['read', 'approvals:decide'])
        ->and(decideIntrospect($this, $after['access_token'])['scopes'])->toBe(['read', 'draft']);

    bearer($this, $before['access_token'])->getJson('/api/v1/me/approvals/waiting')->assertOk();
    bearer($this, $after['access_token'])->getJson('/api/v1/me/approvals/waiting')
        ->assertForbidden()->assertJsonPath('error', 'insufficient_scope');

    // Refreshing the older grant carries the scope over; no new consent.
    $refreshed = decideRefresh($this, $client, $before['refresh_token']);
    expect(decideIntrospect($this, $refreshed['access_token'])['scopes'])->toBe(['read', 'approvals:decide']);
    bearer($this, $refreshed['access_token'])->getJson('/api/v1/me/approvals/waiting')->assertOk();
});

it('API keys: approvals:decide reaches the approver routes, a key without it is refused', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $key = function (array $scopes) use ($user) {
        $plain = $this->actingAs($user, 'web')
            ->postJson('/api/v1/me/api-keys', ['name' => 'Claude', 'scopes' => $scopes])
            ->assertCreated()->json('data.key');
        app('auth')->forgetGuards();

        return $plain;
    };

    $with = $key(['read', 'approvals:decide']);
    $without = $key(['read', 'draft', 'approve', 'publish']);

    expect(decideIntrospect($this, $with)['scopes'])->toBe(['read', 'approvals:decide'])
        ->and(decideIntrospect($this, $without)['scopes'])->toBe(['read', 'draft', 'approve', 'publish']);

    bearer($this, $with)->getJson('/api/v1/me/reports/shared')->assertOk();
    bearer($this, $without)->getJson('/api/v1/me/reports/shared')
        ->assertForbidden()->assertJsonPath('error', 'insufficient_scope');
});
