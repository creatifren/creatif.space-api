<?php

use Illuminate\Support\Str;

/*
 * Blackbox B5: /oauth/token answered 25 bad requests in a row with 400, no
 * 429 and no rate-limit headers; 401s on /api/v1 carried no rate-limit
 * headers either. Refusals are now counted per address on /oauth/token,
 * /oauth/register and /oauth/authorize, the token endpoint has a per-IP
 * ceiling across clients, and API 401s carry X-RateLimit-* and turn into
 * a 429 past the limit.
 */

beforeEach(function () {
    ensurePassportKeys();
    config(['app.url' => 'https://api.creatif.space']);
});

function badToken($test, ?string $clientId = null)
{
    return $test->post('/oauth/token', [
        'grant_type' => 'refresh_token',
        'client_id' => $clientId ?? (string) Str::uuid(),
        'refresh_token' => 'garbage',
    ]);
}

it('answers a run of bad token requests with a 429 and headers, whatever client_id each one names', function () {
    foreach (range(1, 20) as $_) {
        $response = badToken($this);
        expect($response->getStatusCode())->toBeIn([400, 401]);
    }

    badToken($this)
        ->assertStatus(429)
        ->assertJsonPath('error', 'temporarily_unavailable')
        ->assertHeader('Retry-After')
        ->assertHeader('X-RateLimit-Limit', '20')
        ->assertHeader('X-RateLimit-Remaining', '0');
});

it('sends the route throttle headers on a refused token request', function () {
    badToken($this, mcpClient()->getKey())
        ->assertStatus(400)
        ->assertHeader('X-RateLimit-Limit')
        ->assertHeader('X-RateLimit-Remaining');
});

it('counts refused registrations and authorize requests from the same address too', function () {
    config(['mcp.oauth_rate_limits.failures' => 2]);

    $this->postJson('/oauth/register', ['redirect_uris' => ['javascript:alert(1)']])->assertStatus(400);
    $this->post('/oauth/token', ['grant_type' => 'password'])->assertStatus(400);

    $this->postJson('/oauth/register', ['redirect_uris' => ['javascript:alert(1)']])
        ->assertStatus(429)->assertJsonPath('error', 'temporarily_unavailable')->assertHeader('Retry-After');
});

it('never turns a good request into a 429', function () {
    config(['mcp.oauth_rate_limits.failures' => 1]);
    badToken($this);
    badToken($this)->assertStatus(429);

    $this->postJson('/oauth/register', ['client_name' => 'Claude', 'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback']])
        ->assertCreated();
});

it('caps the token endpoint per address across clients', function () {
    config(['mcp.oauth_rate_limits.token_ip' => 2, 'mcp.oauth_rate_limits.failures' => 100]);

    badToken($this);
    badToken($this);
    badToken($this)->assertStatus(429)->assertHeader('Retry-After');
});

describe('API 401', function () {
    it('carries X-RateLimit-Limit and -Remaining, and becomes a 429 past the limit', function () {
        $this->getJson('/api/v1/me')->assertUnauthorized()
            ->assertHeader('X-RateLimit-Limit', '20')
            ->assertHeader('X-RateLimit-Remaining', '19')
            ->assertHeaderMissing('Retry-After');

        foreach (range(2, 20) as $_) {
            $this->getJson('/api/v1/me')->assertUnauthorized();
        }

        $this->getJson('/api/v1/me')
            ->assertStatus(429)
            ->assertHeader('X-RateLimit-Limit', '20')
            ->assertHeader('X-RateLimit-Remaining', '0')
            ->assertHeader('Retry-After');
    });

    it('counts each credential on its own: one bad token does not block another', function () {
        foreach (range(1, 21) as $_) {
            $this->withToken('cs_live_bad_one')->getJson('/api/v1/me');
        }
        $this->withToken('cs_live_bad_one')->getJson('/api/v1/me')->assertStatus(429);

        $this->withToken('cs_live_bad_two')->getJson('/api/v1/me')->assertUnauthorized();
    });
});
