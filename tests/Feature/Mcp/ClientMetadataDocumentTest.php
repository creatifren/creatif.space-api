<?php

use App\Models\User;
use App\OAuth\ClientMetadataDocuments;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Passport\Client;
use Tests\TestCase;

/**
 * G10 — OAuth Client ID Metadata Documents (MCP auth spec): a client_id
 * that is an https URL to a JSON document, accepted next to Dynamic Client
 * Registration. The whole authorize → token → refresh → revoke flow works
 * with the URL as client_id; bad documents are invalid_client; DCR is
 * unchanged.
 */
const CIMD_URL = 'https://app.example-client.com/oauth/client.json';
const CIMD_REDIRECT = 'https://app.example-client.com/callback';

beforeEach(function () {
    ensurePassportKeys();
    config(['app.url' => 'https://api.creatif.space', 'app.frontend_url' => 'https://app.creatif.space']);
    // Public DNS without the network: every host "resolves" to a public address.
    app()->instance(ClientMetadataDocuments::class, new ClientMetadataDocuments(fn (string $host) => ['93.184.216.34']));
});

/** @param array<string, mixed> $overrides */
function cimdDocument(array $overrides = []): array
{
    return array_merge([
        'client_id' => CIMD_URL,
        'client_name' => 'Example Agent',
        'redirect_uris' => [CIMD_REDIRECT],
        'grant_types' => ['authorization_code', 'refresh_token'],
        'response_types' => ['code'],
        'token_endpoint_auth_method' => 'none',
    ], $overrides);
}

function fakeCimd(array $document, int $status = 200, array $headers = ['Cache-Control' => 'max-age=3600']): void
{
    Http::fake([CIMD_URL => Http::response($document, $status, $headers)]);
}

/** @return array{response: mixed, verifier: string} */
function cimdAuthorize(TestCase $test, User $user, string $redirect = CIMD_REDIRECT): array
{
    [$verifier, $challenge] = pkcePair();

    return ['verifier' => $verifier, 'response' => $test->actingAs($user, 'web')->get('/oauth/authorize?'.http_build_query([
        'client_id' => CIMD_URL,
        'redirect_uri' => $redirect,
        'response_type' => 'code',
        'scope' => 'read',
        'state' => 'st',
        'code_challenge' => $challenge,
        'code_challenge_method' => 'S256',
    ]))];
}

it('advertises client_id_metadata_document_supported, and keeps the registration endpoint', function () {
    $this->getJson('/.well-known/oauth-authorization-server')
        ->assertOk()
        ->assertJsonPath('client_id_metadata_document_supported', true)
        ->assertJsonPath('registration_endpoint', 'https://api.creatif.space/oauth/register');
});

it('runs the whole flow with the document URL as client_id: consent, code, token, refresh, revoke', function () {
    fakeCimd(cimdDocument());
    $user = User::factory()->create();

    ['response' => $consent, 'verifier' => $verifier] = cimdAuthorize($this, $user);
    $consent->assertOk()->assertSee('Example Agent')->assertSee('app.example-client.com');

    $client = Client::query()->sole();
    expect($client->name)->toBe('Example Agent')
        ->and($client->confidential())->toBeFalse()
        ->and($client->redirect_uris)->toBe([CIMD_REDIRECT]);

    $approve = $this->post('/oauth/authorize', ['state' => 'st', 'client_id' => $client->getKey(), 'auth_token' => session('authToken')]);
    $approve->assertRedirect();
    expect((string) $approve->headers->get('Location'))->toStartWith(CIMD_REDIRECT.'?');
    parse_str((string) parse_url((string) $approve->headers->get('Location'), PHP_URL_QUERY), $query);

    $tokens = $this->post('/oauth/token', [
        'grant_type' => 'authorization_code',
        'client_id' => CIMD_URL,
        'redirect_uri' => CIMD_REDIRECT,
        'code_verifier' => $verifier,
        'code' => $query['code'],
    ])->assertOk()->json();

    $next = $this->post('/oauth/token', ['grant_type' => 'refresh_token', 'client_id' => CIMD_URL, 'refresh_token' => $tokens['refresh_token']])
        ->assertOk()->json();

    $this->post('/oauth/revoke', ['client_id' => CIMD_URL, 'token' => $next['access_token']])->assertOk();
    $this->post('/oauth/token', ['grant_type' => 'refresh_token', 'client_id' => CIMD_URL, 'refresh_token' => $next['refresh_token']])
        ->assertStatus(400)->assertJsonPath('error', 'invalid_grant');

    // One fetch: the document is cached for its max-age.
    Http::assertSentCount(1);
});

it('accepts a document that also lists grants we do not support, like Claude\'s jwt-bearer, but never grants them', function () {
    fakeCimd(cimdDocument([
        'client_name' => 'Claude',
        'client_uri' => 'https://claude.ai',
        'grant_types' => ['authorization_code', 'refresh_token', 'urn:ietf:params:oauth:grant-type:jwt-bearer'],
    ]));

    cimdAuthorize($this, User::factory()->create())['response']->assertOk()->assertSee('Claude');

    $this->post('/oauth/token', ['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'client_id' => CIMD_URL])
        ->assertStatus(400);

    // The stored client is an authorization-code client: the extra grant is never kept.
    expect(Client::query()->sole()->grant_types)->toContain('authorization_code')->not->toContain('urn:ietf:params:oauth:grant-type:jwt-bearer');
});

it('refuses a redirect_uri the document does not list', function () {
    fakeCimd(cimdDocument());

    $response = cimdAuthorize($this, User::factory()->create(), 'https://evil.example/cb')['response'];

    expect($response->getStatusCode())->toBeGreaterThanOrEqual(400)->toBeLessThan(500);
});

it('refetches once the cached copy expires, and follows the document', function () {
    Http::fakeSequence(CIMD_URL)
        ->push(cimdDocument(), 200, ['Cache-Control' => 'max-age=300'])
        ->push(cimdDocument(['client_name' => 'Example Agent 2', 'redirect_uris' => ['https://app.example-client.com/cb2']]), 200, ['Cache-Control' => 'max-age=300']);
    $user = User::factory()->create();

    cimdAuthorize($this, $user)['response']->assertOk();
    $this->travel(301)->seconds();
    cimdAuthorize($this, $user, 'https://app.example-client.com/cb2')['response']->assertOk()->assertSee('Example Agent 2');

    expect(Client::query()->count())->toBe(1)
        ->and(Client::query()->sole()->redirect_uris)->toBe(['https://app.example-client.com/cb2']);
    Http::assertSentCount(2);
});

it('answers invalid_client for a document it cannot accept', function (Closure $fake, string $why) {
    $fake();

    cimdAuthorize($this, User::factory()->create())['response']
        ->assertStatus(400)
        ->assertJsonPath('error', 'invalid_client')
        ->assertJson(fn ($json) => $json->where('error_description', fn ($d) => str_contains(strtolower($d), $why))->etc());
    expect(Client::query()->count())->toBe(0);
})->with([
    'client_id differs from the URL' => [fn () => fakeCimd(cimdDocument(['client_id' => 'https://other.example/client.json'])), 'client_id'],
    'no redirect_uris' => [fn () => fakeCimd(cimdDocument(['redirect_uris' => []])), 'redirect_uris'],
    'javascript: redirect' => [fn () => fakeCimd(cimdDocument(['redirect_uris' => ['javascript:alert(1)']])), 'redirect uri'],
    'confidential auth method' => [fn () => fakeCimd(cimdDocument(['token_endpoint_auth_method' => 'private_key_jwt'])), 'token_endpoint_auth_method'],
    'carries a secret' => [fn () => fakeCimd(cimdDocument(['client_secret' => 'x'])), 'client_secret'],
    'unsupported grant' => [fn () => fakeCimd(cimdDocument(['grant_types' => ['client_credentials']])), 'grant_types'],
    'no code response type' => [fn () => fakeCimd(cimdDocument(['response_types' => ['token']])), 'response_types'],
    'not found' => [fn () => fakeCimd([], 404), '404'],
    'a redirect' => [fn () => Http::fake([CIMD_URL => Http::response('', 302, ['Location' => 'https://elsewhere.example/c.json'])]), '302'],
    'not JSON' => [fn () => Http::fake([CIMD_URL => Http::response('<html>', 200)]), 'json'],
    'too large' => [fn () => fakeCimd(cimdDocument(['client_name' => str_repeat('a', ClientMetadataDocuments::MAX_BYTES)])), 'larger'],
]);

it('never fetches from a private or loopback address', function (string $ip) {
    app()->instance(ClientMetadataDocuments::class, new ClientMetadataDocuments(fn (string $host) => [$ip]));
    Http::fake();

    cimdAuthorize($this, User::factory()->create())['response']
        ->assertStatus(400)
        ->assertJsonPath('error', 'invalid_client');
    Http::assertNothingSent();
})->with(['127.0.0.1', '10.0.0.5', '192.168.1.10', '169.254.169.254', '::1']);

it('treats only https URLs with a path as metadata documents', function (mixed $id, bool $isUrl) {
    expect(ClientMetadataDocuments::isUrl($id))->toBe($isUrl);
})->with([
    [CIMD_URL, true],
    ['https://app.example-client.com/', false],
    ['https://app.example-client.com', false],
    ['http://app.example-client.com/client.json', false],
    ['https://user@app.example-client.com/client.json', false],
    ['https://app.example-client.com/a/../client.json', false],
    ['https://app.example-client.com/client.json#x', false],
    ['9d1e4b2a-0000-4000-8000-000000000000', false],
    [null, false],
]);

it('answers the token endpoint with 401 invalid_client for an unusable document', function () {
    fakeCimd([], 500);

    $this->post('/oauth/token', ['grant_type' => 'refresh_token', 'client_id' => CIMD_URL, 'refresh_token' => 'x'])
        ->assertStatus(401)
        ->assertJsonPath('error', 'invalid_client');
});

it('leaves Dynamic Client Registration working as before', function () {
    $id = $this->postJson('/oauth/register', ['client_name' => 'Claude', 'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback']])
        ->assertCreated()->json('client_id');

    $tokens = oauthTokens($this, User::factory()->create(), Client::query()->findOrFail($id));

    expect($tokens['access_token'])->toBeString();
    expect(DB::table('oauth_client_metadata_documents')->count())->toBe(0);
});
