<?php

use App\Models\Plan;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use Tests\TestCase;

/**
 * Plans are seeded for every feature test: since Fase 5, "Free" is a row in
 * the database rather than a constant, so quota enforcement has nothing to
 * read without it. Seeding here rather than per-test keeps the existing
 * Space tests honest — they exercise the real Free ceilings.
 */
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(fn () => $this->seed(PlanSeeder::class))
    ->in('Feature');

/**
 * Change a seeded plan row's features / quotas for one test — e.g. Free
 * with `spaces` on, so a Space test exercises the Space rules rather than
 * the plans v2 gate (which has its own tests in PlansV2Test).
 *
 * @param  array<string, bool>  $features
 * @param  array<string, int|null>  $quotas
 */
function planWith(string $key, array $features = [], array $quotas = []): Plan
{
    $plan = Plan::query()->where('key', $key)->sole();
    $plan->update([
        'features' => [...$plan->features, ...$features],
        'quotas' => [...$plan->quotas, ...$quotas],
    ]);

    return $plan;
}

/*
 * MCP / OAuth helpers (tests/Feature/Mcp).
 */

/** Passport's RSA pair, generated once per checkout (storage/*.key is ignored by git). */
function ensurePassportKeys(): void
{
    if (! file_exists(storage_path('oauth-private.key')) || ! file_exists(storage_path('oauth-public.key'))) {
        Artisan::call('passport:keys', ['--force' => true]);
    }
}

/** A public (PKCE-only) client, as Dynamic Client Registration would make. */
function mcpClient(string $redirect = 'https://claude.ai/api/mcp/auth_callback'): Client
{
    return app(ClientRepository::class)
        ->createAuthorizationCodeGrantClient('Claude', [$redirect], false);
}

/** @return array{0: string, 1: string} [verifier, S256 challenge] */
function pkcePair(): array
{
    $verifier = Str::random(64);

    return [$verifier, rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=')];
}

/**
 * The whole browser + token dance for $user and $client, returning the
 * token endpoint's JSON (access_token, refresh_token, expires_in, …).
 *
 * @return array<string, mixed>
 */
function oauthTokens(TestCase $test, User $user, Client $client, string $scope = 'read'): array
{
    [$verifier, $challenge] = pkcePair();
    $redirect = $client->redirect_uris[0];

    $test->actingAs($user, 'web')
        ->get('/oauth/authorize?'.http_build_query([
            'client_id' => $client->getKey(),
            'redirect_uri' => $redirect,
            'response_type' => 'code',
            'scope' => $scope,
            'state' => 'xyz',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]))
        ->assertOk();

    $approve = $test->post('/oauth/authorize', [
        'state' => 'xyz',
        'client_id' => $client->getKey(),
        'auth_token' => session('authToken'),
    ]);
    $approve->assertRedirect();
    parse_str((string) parse_url((string) $approve->headers->get('Location'), PHP_URL_QUERY), $query);

    return $test->post('/oauth/token', [
        'grant_type' => 'authorization_code',
        'client_id' => $client->getKey(),
        'redirect_uri' => $redirect,
        'code_verifier' => $verifier,
        'code' => $query['code'],
    ])->assertOk()->json();
}

/** Forget any signed-in session so the next request is the bearer token alone. */
function bearer(TestCase $test, string $token): TestCase
{
    app('auth')->forgetGuards();
    $test->flushSession();

    return $test->withToken($token);
}

// Media v2 test helpers (mediaUser, mediaFile, postWith, …).
require_once __DIR__.'/Feature/Media/MediaHelpers.php';
