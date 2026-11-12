<?php

use App\Models\User;
use Carbon\CarbonImmutable;
use Laravel\Passport\Passport;
use Laravel\Passport\Client;
use Laravel\Passport\RefreshToken;
use Tests\TestCase;

/**
 * G9 — OAuth hygiene for directory review: dead refresh tokens are
 * invalid_grant, public clients' refresh tokens rotate, Claude's callback
 * and loopback redirect URIs register and complete the flow, and the
 * discovery / registration / token endpoints answer well within 10 s.
 * Scopes and consent text are untouched.
 */
beforeEach(function () {
    ensurePassportKeys();
    config([
        'app.url' => 'https://api.creatif.space',
        'app.frontend_url' => 'https://app.creatif.space',
        'mcp.resource_url' => 'https://mcp.creatif.space/mcp',
    ]);
});

const ENDPOINT_BUDGET_SECONDS = 10.0;

function refresh(TestCase $test, Client $client, string $token)
{
    return $test->post('/oauth/token', [
        'grant_type' => 'refresh_token',
        'client_id' => $client->getKey(),
        'refresh_token' => $token,
    ]);
}

function assertInvalidGrant($response): void
{
    $response->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
}

/** Runs $fn and fails if it took longer than the endpoint budget. */
function withinBudget(callable $fn)
{
    $started = microtime(true);
    $result = $fn();
    expect(microtime(true) - $started)->toBeLessThan(ENDPOINT_BUDGET_SECONDS);

    return $result;
}

describe('dead refresh tokens → invalid_grant', function () {
    it('after rotation: the old refresh token is dead once used (public client)', function () {
        $client = mcpClient();
        expect($client->confidential())->toBeFalse();
        $tokens = oauthTokens($this, User::factory()->create(), $client);

        $next = refresh($this, $client, $tokens['refresh_token'])->assertOk()->json();
        expect($next['refresh_token'])->toBeString()->not->toBe($tokens['refresh_token']);

        assertInvalidGrant(refresh($this, $client, $tokens['refresh_token']));
        // The new one still works, once.
        refresh($this, $client, $next['refresh_token'])->assertOk();
        assertInvalidGrant(refresh($this, $client, $next['refresh_token']));
    });

    it('after revocation (RFC 7009)', function () {
        $client = mcpClient();
        $tokens = oauthTokens($this, User::factory()->create(), $client);

        $this->post('/oauth/revoke', ['client_id' => $client->getKey(), 'token' => $tokens['refresh_token'], 'token_type_hint' => 'refresh_token'])->assertOk();

        assertInvalidGrant(refresh($this, $client, $tokens['refresh_token']));
    });

    it('when revoked in the database (disconnected from Connected apps)', function () {
        $client = mcpClient();
        $tokens = oauthTokens($this, User::factory()->create(), $client);
        RefreshToken::query()->update(['revoked' => true]);

        assertInvalidGrant(refresh($this, $client, $tokens['refresh_token']));
    });

    it('when expired', function () {
        // League checks expiry against the real clock (time()), so Carbon
        // time travel cannot age a token: issue one that is already past
        // its expiry instead. Set before the token server is first built.
        Passport::refreshTokensExpireIn(CarbonImmutable::now()->subMinute());
        $client = mcpClient();
        $tokens = oauthTokens($this, User::factory()->create(), $client);

        assertInvalidGrant(refresh($this, $client, $tokens['refresh_token']));
    });

    it('when unknown or unreadable', function (string $token) {
        assertInvalidGrant(refresh($this, mcpClient(), $token));
    })->with(['garbage', 'def50200'.str_repeat('ab', 120)]);

    it('when presented by another client', function () {
        $client = mcpClient();
        $other = mcpClient('https://other.test/cb');
        $tokens = oauthTokens($this, User::factory()->create(), $client);

        assertInvalidGrant(refresh($this, $other, $tokens['refresh_token']));
    });

    it('leaves a missing refresh_token as invalid_request', function () {
        $this->post('/oauth/token', ['grant_type' => 'refresh_token', 'client_id' => mcpClient()->getKey()])
            ->assertStatus(400)
            ->assertJsonPath('error', 'invalid_request');
    });
});

describe('redirect URIs', function () {
    it('registers and completes the flow for Claude and loopback callbacks', function (string $redirect) {
        $id = $this->postJson('/oauth/register', [
            'client_name' => 'MCP client',
            'redirect_uris' => [$redirect],
            'token_endpoint_auth_method' => 'none',
        ])->assertCreated()->assertJsonPath('redirect_uris', [$redirect])->json('client_id');

        $tokens = oauthTokens($this, User::factory()->create(), Client::query()->findOrFail($id));

        expect($tokens['access_token'])->toBeString()->and($tokens['refresh_token'])->toBeString();
    })->with([
        'Claude' => 'https://claude.ai/api/mcp/auth_callback',
        'loopback 127.0.0.1' => 'http://127.0.0.1:33418/callback',
        'loopback localhost' => 'http://localhost:6274/oauth/callback',
        'loopback ::1' => 'http://[::1]:8080/cb',
    ]);

    it('still refuses plain http off loopback', function () {
        $this->postJson('/oauth/register', ['redirect_uris' => ['http://claude.ai/api/mcp/auth_callback']])
            ->assertStatus(400)
            ->assertJsonPath('error', 'invalid_redirect_uri');
    });
});

describe('response-time budget (< 10 s)', function () {
    it('discovery answers within budget', function (string $path) {
        withinBudget(fn () => $this->getJson($path)->assertOk());
    })->with(['/.well-known/oauth-authorization-server', '/.well-known/oauth-protected-resource', '/.well-known/oauth-protected-resource/mcp']);

    it('registration answers within budget', function () {
        withinBudget(fn () => $this->postJson('/oauth/register', [
            'client_name' => 'Claude',
            'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'],
        ])->assertCreated());
    });

    it('the token endpoint answers within budget (code and refresh)', function () {
        $client = mcpClient();
        $tokens = withinBudget(fn () => oauthTokens($this, User::factory()->create(), $client));
        withinBudget(fn () => refresh($this, $client, $tokens['refresh_token'])->assertOk());
    });
});
