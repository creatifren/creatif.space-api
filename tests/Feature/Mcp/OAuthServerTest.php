<?php

use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\RefreshToken;
use Laravel\Passport\Token;

beforeEach(function () {
    ensurePassportKeys();
    config([
        'app.url' => 'https://api.creatif.space',
        'app.frontend_url' => 'https://app.creatif.space',
        'mcp.resource_url' => 'https://mcp.creatif.space/mcp',
    ]);
});

describe('discovery', function () {
    it('publishes RFC 8414 authorization server metadata', function () {
        $this->getJson('/.well-known/oauth-authorization-server')
            ->assertOk()
            ->assertJsonPath('issuer', 'https://api.creatif.space')
            ->assertJsonPath('authorization_endpoint', 'https://api.creatif.space/oauth/authorize')
            ->assertJsonPath('token_endpoint', 'https://api.creatif.space/oauth/token')
            ->assertJsonPath('registration_endpoint', 'https://api.creatif.space/oauth/register')
            ->assertJsonPath('revocation_endpoint', 'https://api.creatif.space/oauth/revoke')
            ->assertJsonPath('code_challenge_methods_supported', ['S256'])
            ->assertJsonPath('grant_types_supported', ['authorization_code', 'refresh_token'])
            ->assertJsonPath('scopes_supported', ['read', 'draft', 'approve', 'publish', 'approvals:decide']);
    });

    it('publishes RFC 9728 protected resource metadata for the MCP server', function (string $path) {
        $this->getJson($path)
            ->assertOk()
            ->assertJsonPath('resource', 'https://mcp.creatif.space/mcp')
            ->assertJsonPath('authorization_servers', ['https://api.creatif.space'])
            ->assertJsonPath('bearer_methods_supported', ['header']);
    })->with(['/.well-known/oauth-protected-resource', '/.well-known/oauth-protected-resource/mcp']);
});

describe('dynamic client registration', function () {
    it('registers a public client', function () {
        $response = $this->postJson('/oauth/register', [
            'client_name' => 'Claude',
            'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'],
            'token_endpoint_auth_method' => 'none',
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
        ])->assertCreated()
            ->assertJsonPath('client_name', 'Claude')
            ->assertJsonPath('token_endpoint_auth_method', 'none')
            ->assertJsonMissingPath('client_secret');

        $client = Client::query()->findOrFail($response->json('client_id'));
        expect($client->confidential())->toBeFalse()
            ->and($client->redirect_uris)->toBe(['https://claude.ai/api/mcp/auth_callback']);
    });

    it('returns a secret once for a confidential client, stored hashed', function () {
        $response = $this->postJson('/oauth/register', [
            'client_name' => 'ChatGPT',
            'redirect_uris' => ['https://chatgpt.com/connector_platform_oauth_redirect'],
            'token_endpoint_auth_method' => 'client_secret_post',
        ])->assertCreated();

        $secret = $response->json('client_secret');
        $client = Client::query()->findOrFail($response->json('client_id'));

        expect($secret)->toBeString()->not->toBe($client->secret)
            ->and(password_verify($secret, $client->secret))->toBeTrue();
    });

    it('accepts loopback http and app schemes', function () {
        $this->postJson('/oauth/register', [
            'redirect_uris' => ['http://127.0.0.1:6274/oauth/callback', 'cursor://anysphere.cursor-retrieval/oauth/callback'],
        ])->assertCreated()->assertJsonPath('client_name', 'MCP client');
    });

    it('refuses dangerous or remote-http redirect URIs', function (string $uri) {
        $this->postJson('/oauth/register', ['redirect_uris' => [$uri]])
            ->assertStatus(400)
            ->assertJsonPath('error', 'invalid_redirect_uri');

        expect(Client::query()->count())->toBe(0);
    })->with(['javascript:alert(1)', 'http://evil.test/cb', 'https://ok.test/cb#frag', 'data:text/html,x']);

    it('refuses unknown grant types and scopes', function () {
        $this->postJson('/oauth/register', [
            'redirect_uris' => ['https://x.test/cb'],
            'grant_types' => ['client_credentials'],
        ])->assertStatus(400)->assertJsonPath('error', 'invalid_client_metadata');

        $this->postJson('/oauth/register', [
            'redirect_uris' => ['https://x.test/cb'],
            'scope' => 'read admin',
        ])->assertStatus(400)->assertJsonPath('error', 'invalid_client_metadata');
    });

    it('can be switched off', function () {
        config(['mcp.registration_enabled' => false]);

        $this->postJson('/oauth/register', ['redirect_uris' => ['https://x.test/cb']])->assertForbidden();
    });
});

describe('authorization code + PKCE', function () {
    it('sends a guest to the frontend login and back to the consent screen', function () {
        $client = mcpClient();
        [, $challenge] = pkcePair();
        $query = http_build_query([
            'client_id' => $client->getKey(),
            'redirect_uri' => $client->redirect_uris[0],
            'response_type' => 'code',
            'scope' => 'read',
            'state' => 's',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]);

        $this->get('/oauth/authorize?'.$query)
            ->assertRedirect('https://app.creatif.space/login?next='.urlencode('/oauth/resume'));

        $intended = session('oauth.intended');
        expect($intended)->toContain('/oauth/authorize?');

        $this->actingAs(User::factory()->create(), 'web')
            ->withSession(['oauth.intended' => $intended])
            ->get('/oauth/resume')
            ->assertRedirect($intended);
    });

    it('never resumes to anything but an authorize URL', function () {
        $this->actingAs(User::factory()->create(), 'web')
            ->withSession(['oauth.intended' => 'https://evil.test/'])
            ->get('/oauth/resume')
            ->assertRedirect('https://app.creatif.space');
    });

    it('requires an S256 code challenge', function (array $pkce) {
        $client = mcpClient();

        $this->actingAs(User::factory()->create(), 'web')
            ->get('/oauth/authorize?'.http_build_query([
                'client_id' => $client->getKey(),
                'redirect_uri' => $client->redirect_uris[0],
                'response_type' => 'code',
                'scope' => 'read',
            ] + $pkce))
            ->assertStatus(400)
            ->assertJsonPath('error', 'invalid_request');
    })->with([
        'missing' => [[]],
        'plain' => [['code_challenge' => 'abc', 'code_challenge_method' => 'plain']],
    ]);

    it('shows the consent screen naming the client and scopes', function () {
        $client = mcpClient();
        [, $challenge] = pkcePair();
        $user = User::factory()->create(['email' => 'rizal@example.test']);

        $this->actingAs($user, 'web')
            ->get('/oauth/authorize?'.http_build_query([
                'client_id' => $client->getKey(),
                'redirect_uri' => $client->redirect_uris[0],
                'response_type' => 'code',
                'scope' => 'read draft',
                'state' => 's',
                'code_challenge' => $challenge,
                'code_challenge_method' => 'S256',
            ]))
            ->assertOk()
            ->assertSee('Claude wants to connect to your account')
            ->assertSee(config('mcp.scopes.draft'))
            ->assertSee('rizal@example.test');
    });

    it('issues a 1 h access token and a 30 d rotating refresh token', function () {
        $client = mcpClient();
        $user = User::factory()->create();

        $tokens = oauthTokens($this, $user, $client, 'read draft');

        expect($tokens['token_type'])->toBe('Bearer')
            ->and($tokens['expires_in'])->toBeGreaterThan(3500)->toBeLessThanOrEqual(3600)
            ->and($tokens['refresh_token'])->toBeString();

        $refresh = RefreshToken::query()->sole();
        expect($refresh->expires_at->diffInDays(now(), true))->toBeGreaterThan(29.9);

        $again = $this->post('/oauth/token', [
            'grant_type' => 'refresh_token',
            'client_id' => $client->getKey(),
            'refresh_token' => $tokens['refresh_token'],
        ])->assertOk()->json();

        expect($again['access_token'])->not->toBe($tokens['access_token']);

        // The old refresh token was rotated out.
        $this->post('/oauth/token', [
            'grant_type' => 'refresh_token',
            'client_id' => $client->getKey(),
            'refresh_token' => $tokens['refresh_token'],
        ])->assertStatus(400);
    });

    it('refuses a code exchanged with the wrong verifier', function () {
        $client = mcpClient();
        [, $challenge] = pkcePair();

        $this->actingAs(User::factory()->create(), 'web')
            ->get('/oauth/authorize?'.http_build_query([
                'client_id' => $client->getKey(),
                'redirect_uri' => $client->redirect_uris[0],
                'response_type' => 'code',
                'scope' => 'read',
                'state' => 's',
                'code_challenge' => $challenge,
                'code_challenge_method' => 'S256',
            ]));
        $approve = $this->post('/oauth/authorize', [
            'state' => 's', 'client_id' => $client->getKey(), 'auth_token' => session('authToken'),
        ]);
        parse_str((string) parse_url((string) $approve->headers->get('Location'), PHP_URL_QUERY), $query);

        $this->post('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $client->getKey(),
            'redirect_uri' => $client->redirect_uris[0],
            'code_verifier' => str_repeat('x', 64),
            'code' => $query['code'],
        ])->assertStatus(400);
    });

    it('lets the access token call the V1 API', function () {
        $client = mcpClient();
        $user = User::factory()->create();
        SocialAccount::factory()->for($user)->create(['username' => '@studio']);
        $tokens = oauthTokens($this, $user, $client);

        bearer($this, $tokens['access_token'])
            ->getJson('/api/v1/social-accounts')
            ->assertOk()
            ->assertJsonPath('data.0.username', '@studio');
    });
});

describe('revocation', function () {
    it('revokes an access token and its refresh token (RFC 7009)', function () {
        $client = mcpClient();
        $user = User::factory()->create();
        $tokens = oauthTokens($this, $user, $client);

        $this->post('/oauth/revoke', [
            'client_id' => $client->getKey(),
            'token' => $tokens['access_token'],
        ])->assertOk();

        expect(Token::query()->sole()->revoked)->toBeTrue()
            ->and(RefreshToken::query()->sole()->revoked)->toBeTrue();

        bearer($this, $tokens['access_token'])->getJson('/api/v1/me')->assertUnauthorized();
    });

    it('revokes by refresh token too', function () {
        $client = mcpClient();
        $tokens = oauthTokens($this, User::factory()->create(), $client);

        $this->post('/oauth/revoke', [
            'client_id' => $client->getKey(),
            'token' => $tokens['refresh_token'],
            'token_type_hint' => 'refresh_token',
        ])->assertOk();

        expect(Token::query()->sole()->revoked)->toBeTrue();
    });

    it('ignores another client\'s token and unknown tokens', function () {
        $client = mcpClient();
        $other = mcpClient('https://other.test/cb');
        $tokens = oauthTokens($this, User::factory()->create(), $client);

        $this->post('/oauth/revoke', ['client_id' => $other->getKey(), 'token' => $tokens['access_token']])->assertOk();
        $this->post('/oauth/revoke', ['client_id' => $client->getKey(), 'token' => 'garbage'])->assertOk();

        expect(Token::query()->sole()->revoked)->toBeFalse();
    });

    it('requires the client secret for a confidential client', function () {
        $client = app(ClientRepository::class)
            ->createAuthorizationCodeGrantClient('ChatGPT', ['https://chatgpt.com/cb'], true);

        $this->post('/oauth/revoke', ['client_id' => $client->getKey(), 'token' => 'x'])->assertUnauthorized();
        $this->post('/oauth/revoke', ['client_id' => $client->getKey(), 'client_secret' => $client->plainSecret, 'token' => 'x'])->assertOk();
    });

    it('lists and disconnects connected apps', function () {
        $client = mcpClient();
        $user = User::factory()->create();
        $tokens = oauthTokens($this, $user, $client, 'read draft');

        $this->actingAs($user, 'web')
            ->getJson('/api/v1/me/connected-apps')
            ->assertOk()
            ->assertJsonPath('data.0.id', (string) $client->getKey())
            ->assertJsonPath('data.0.name', 'Claude')
            ->assertJsonPath('data.0.scopes', ['read', 'draft']);

        $this->deleteJson('/api/v1/me/connected-apps/'.$client->getKey())->assertNoContent();
        $this->deleteJson('/api/v1/me/connected-apps/'.$client->getKey())->assertNotFound();

        bearer($this, $tokens['access_token'])->getJson('/api/v1/me')->assertUnauthorized();
    });

    it('records last use of an access token at most once per 5 minutes and lists it', function () {
        $client = mcpClient();
        $user = User::factory()->create();
        $tokens = oauthTokens($this, $user, $client, 'read draft');
        $token = fn () => Token::query()->where('user_id', $user->getKey())->where('revoked', false)->sole();

        expect($token()->getAttribute('last_used_at'))->toBeNull();
        $this->actingAs($user, 'web')->getJson('/api/v1/me/connected-apps')->assertJsonPath('data.0.last_used_at', null);
        auth()->forgetGuards();

        $this->travelTo(now()->startOfMinute());
        bearer($this, $tokens['access_token'])->getJson('/api/v1/me')->assertOk();
        $first = Carbon\Carbon::parse($token()->getAttribute('last_used_at'));
        expect($first->equalTo(now()))->toBeTrue();

        // Within 5 minutes: no new write.
        $this->travel(4)->minutes();
        auth()->forgetGuards();
        DB::enableQueryLog();
        bearer($this, $tokens['access_token'])->getJson('/api/v1/me')->assertOk();
        $writes = collect(DB::getQueryLog())->filter(fn ($q) => preg_match('/^\s*update\W+oauth_access_tokens\W/i', $q['query']) === 1);
        DB::disableQueryLog();
        expect($writes)->toHaveCount(0);
        expect(Carbon\Carbon::parse($token()->getAttribute('last_used_at'))->equalTo($first))->toBeTrue();

        // Past 5 minutes: written again.
        $this->travel(2)->minutes();
        auth()->forgetGuards();
        bearer($this, $tokens['access_token'])->getJson('/api/v1/me')->assertOk();
        $second = Carbon\Carbon::parse($token()->getAttribute('last_used_at'));
        expect($second->equalTo(now()))->toBeTrue();

        auth()->forgetGuards();
        $this->actingAs($user, 'web')
            ->getJson('/api/v1/me/connected-apps')
            ->assertOk()
            ->assertJsonPath('data.0.last_used_at', $second->toIso8601String());
    });

    it('never shows another user\'s use of the same app', function () {
        $client = mcpClient();
        $me = User::factory()->create();
        $other = User::factory()->create();
        oauthTokens($this, $me, $client);
        $theirs = oauthTokens($this, $other, $client);
        auth()->forgetGuards();
        bearer($this, $theirs['access_token'])->getJson('/api/v1/me')->assertOk();
        auth()->forgetGuards();

        $this->actingAs($me, 'web')->getJson('/api/v1/me/connected-apps')->assertJsonPath('data.0.last_used_at', null);
    });

    it('does not let a suspended account use its tokens', function () {
        $client = mcpClient();
        $user = User::factory()->create();
        $tokens = oauthTokens($this, $user, $client);
        $user->update(['status' => 'suspended']);

        bearer($this, $tokens['access_token'])->getJson('/api/v1/me')->assertUnauthorized();
    });
});
