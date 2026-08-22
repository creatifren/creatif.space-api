<?php

use App\Models\Client;
use App\Models\User;
use Illuminate\Support\Facades\Http;

/**
 * Google's token response, with the id_token claims we actually read.
 *
 * @param  array<string, mixed>  $claims
 */
function googleTokenResponse(array $claims): array
{
    $payload = rtrim(strtr(base64_encode(json_encode($claims) ?: '{}'), '+/', '-_'), '=');

    return [
        'access_token' => 'at-1',
        'id_token' => "header.{$payload}.signature",
    ];
}

it('sends the visitor to Google with identity scope only', function () {
    $response = $this->get('/auth/google/client/redirect?return=/rani/winter-noel');

    $target = $response->headers->get('Location');

    expect($target)->toContain('accounts.google.com')
        ->and($target)->toContain(urlencode('openid email profile'))
        // The approver never gets asked for Drive.
        ->and($target)->not->toContain('drive');
});

it('creates the client and starts the session on the client guard', function () {
    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response(googleTokenResponse([
            'sub' => 'google-abc',
            'email' => 'andi@winternoel.com',
            'name' => 'Andi Wijaya',
        ])),
    ]);

    $this->withSession([
        'client_oauth_state' => 'state-1',
        'client_oauth_return' => '/rani/winter-noel',
    ])->get('/auth/google/client/callback?state=state-1&code=code-1')
        ->assertRedirectContains('/rani/winter-noel?approve=ready');

    $client = Client::query()->where('email', 'andi@winternoel.com')->first();

    expect($client)->not->toBeNull()
        ->and($client->google_id)->toBe('google-abc')
        ->and($client->name)->toBe('Andi Wijaya')
        ->and(auth('client')->id())->toBe($client->id)
        // The creator session is untouched.
        ->and(auth('web')->id())->toBeNull();
});

it('links the client to a creator account with the same address', function () {
    $creator = User::factory()->create(['email' => 'rani@creatif.space']);

    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response(googleTokenResponse([
            'sub' => 'google-rani',
            'email' => 'rani@creatif.space',
            'name' => 'Rani',
        ])),
    ]);

    $this->withSession(['client_oauth_state' => 's', 'client_oauth_return' => '/'])
        ->get('/auth/google/client/callback?state=s&code=c');

    expect(Client::query()->where('email', 'rani@creatif.space')->value('user_id'))
        ->toBe($creator->id);
});

it('signs an existing client back in rather than duplicating them', function () {
    $existing = Client::factory()->create([
        'google_id' => 'google-abc',
        'email' => 'andi@winternoel.com',
    ]);

    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response(googleTokenResponse([
            'sub' => 'google-abc',
            'email' => 'andi@winternoel.com',
            'name' => 'Andi Wijaya',
        ])),
    ]);

    $this->withSession(['client_oauth_state' => 's', 'client_oauth_return' => '/'])
        ->get('/auth/google/client/callback?state=s&code=c');

    expect(Client::query()->count())->toBe(1)
        ->and(auth('client')->id())->toBe($existing->id);
});

it('refuses a mismatched state and a missing code', function () {
    $this->withSession(['client_oauth_state' => 'real', 'client_oauth_return' => '/rani/noel'])
        ->get('/auth/google/client/callback?state=forged&code=c')
        ->assertRedirectContains('approve=error');

    expect(auth('client')->check())->toBeFalse();

    $this->withSession(['client_oauth_state' => 'real', 'client_oauth_return' => '/'])
        ->get('/auth/google/client/callback?state=real')
        ->assertRedirectContains('approve=error');
});

it('never bounces an off-site return path', function () {
    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response(googleTokenResponse([
            'sub' => 'g', 'email' => 'a@b.test', 'name' => 'A',
        ])),
    ]);

    $this->withSession([
        'client_oauth_state' => 's',
        'client_oauth_return' => 'https://evil.test/steal',
    ])->get('/auth/google/client/callback?state=s&code=c')
        ->assertRedirect(config('app.frontend_url').'/?approve=ready');
});

/**
 * `login(remember: true)` writes `id|remember_token|hmac(password)` into the
 * recaller cookie, and `clients` has no password column. Under
 * `Model::shouldBeStrict()` that threw MissingAttributeException and sent the
 * buyer back to the Space with ?approve=error instead of signed in.
 *
 * The callback tests above miss it: a brand-new Client is `wasRecentlyCreated`
 * (strict mode stays quiet), and a returning one is re-hydrated by
 * `fill()->save()`. Only a client read fresh from the database — every real
 * second visit — takes the path that broke, so this test loads one that way.
 */
it('remembers a client read fresh from the database, though it has no password', function () {
    Client::factory()->create(['email' => 'andi@winternoel.com']);
    $client = Client::query()->where('email', 'andi@winternoel.com')->firstOrFail();

    auth('client')->login($client, remember: true);

    expect(auth('client')->id())->toBe($client->id);
});

it('signs the client out without touching the creator session', function () {
    $creator = User::factory()->create();
    $client = Client::factory()->create();

    $this->actingAs($creator)->actingAs($client, 'client')
        ->postJson('/auth/client/logout')
        ->assertNoContent();

    expect(auth('client')->check())->toBeFalse();
});
