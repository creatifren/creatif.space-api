<?php

use App\Models\User;
use Laravel\Passport\Client;
use Laravel\Passport\Token;

/*
 * Blackbox B1: the consent screen opened more than once in one browser
 * session (retry, a second tab, Back) used to make Allow on the earlier
 * screen a bare 403 "The provided auth token for the request is different
 * from the session auth token". Each screen now carries its own pending
 * request; each works once; a used or unknown one never shows a bare 403.
 */

beforeEach(function () {
    ensurePassportKeys();
    config(['app.url' => 'https://api.creatif.space', 'app.frontend_url' => 'https://app.creatif.space']);
});

/** Open the consent screen for $state; returns its auth token. */
function mcOpen($test, User $user, Client $client, string $state): string
{
    [, $challenge] = pkcePair();
    $test->actingAs($user, 'web')->get('/oauth/authorize?'.http_build_query([
        'client_id' => (string) $client->getKey(),
        'redirect_uri' => $client->redirect_uris[0],
        'response_type' => 'code',
        'scope' => 'read',
        'state' => $state,
        'code_challenge' => $challenge,
        'code_challenge_method' => 'S256',
    ]))->assertOk();

    return (string) session('authToken');
}

/** @return array<string, string> the query of a redirect */
function mcQuery(string $location): array
{
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    return $query;
}

it('lets Allow work on both of two open consent screens', function () {
    $client = mcpClient();
    $user = User::factory()->create();
    $first = mcOpen($this, $user, $client, 'tab-one');
    $second = mcOpen($this, $user, $client, 'tab-two');
    expect($first)->not->toBe($second);

    $one = (string) $this->post('/oauth/authorize', ['state' => 'tab-one', 'client_id' => $client->getKey(), 'auth_token' => $first])
        ->assertRedirect()->headers->get('Location');
    expect($one)->toStartWith($client->redirect_uris[0].'?')
        ->and(mcQuery($one))->toHaveKey('code')
        ->and(mcQuery($one)['state'])->toBe('tab-one');

    $two = (string) $this->post('/oauth/authorize', ['state' => 'tab-two', 'client_id' => $client->getKey(), 'auth_token' => $second])
        ->assertRedirect()->headers->get('Location');
    expect($two)->toStartWith($client->redirect_uris[0].'?')
        ->and(mcQuery($two))->toHaveKey('code')
        ->and(mcQuery($two)['state'])->toBe('tab-two');
});

it('works after the same consent screen was opened twice (retry, Back)', function () {
    $client = mcpClient();
    $user = User::factory()->create();
    $earlier = mcOpen($this, $user, $client, 'same');
    mcOpen($this, $user, $client, 'same');

    $location = (string) $this->post('/oauth/authorize', ['state' => 'same', 'client_id' => $client->getKey(), 'auth_token' => $earlier])
        ->assertRedirect()->headers->get('Location');
    expect(mcQuery($location))->toHaveKey('code');
});

it('refuses a replayed token: no second code, no bare 403', function () {
    $client = mcpClient();
    $user = User::factory()->create();
    $token = mcOpen($this, $user, $client, 'once');

    $this->post('/oauth/authorize', ['state' => 'once', 'client_id' => $client->getKey(), 'auth_token' => $token])->assertRedirect();

    $replay = $this->post('/oauth/authorize', ['state' => 'once', 'client_id' => $client->getKey(), 'auth_token' => $token]);
    expect($replay->getStatusCode())->not->toBe(403)
        ->and((string) $replay->headers->get('Location'))->not->toContain('code=');
    $replay->assertStatus(400)->assertSee('This connection request has expired');
});

it('sends a used token back to the consent screen still open in another tab', function () {
    $client = mcpClient();
    $user = User::factory()->create();
    $first = mcOpen($this, $user, $client, 'tab-one');
    mcOpen($this, $user, $client, 'tab-two');

    $this->post('/oauth/authorize', ['state' => 'tab-one', 'client_id' => $client->getKey(), 'auth_token' => $first])->assertRedirect();
    $codes = Token::query()->count();

    $location = (string) $this->post('/oauth/authorize', ['state' => 'tab-one', 'client_id' => $client->getKey(), 'auth_token' => $first])
        ->assertRedirect()->headers->get('Location');
    expect(parse_url($location, PHP_URL_PATH))->toBe('/oauth/authorize')
        ->and(mcQuery($location)['state'])->toBe('tab-two')
        ->and(Token::query()->count())->toBe($codes);
});

it('answers an unknown token with the expired page, not a bare 403', function () {
    $client = mcpClient();

    $this->actingAs(User::factory()->create(), 'web')
        ->post('/oauth/authorize', ['state' => 'x', 'client_id' => $client->getKey(), 'auth_token' => 'not-a-token'])
        ->assertStatus(400)->assertSee('This connection request has expired');
});

it('lets Deny work on the earlier of two screens', function () {
    $client = mcpClient();
    $user = User::factory()->create();
    $first = mcOpen($this, $user, $client, 'tab-one');
    mcOpen($this, $user, $client, 'tab-two');

    $location = (string) $this->delete('/oauth/authorize', ['state' => 'tab-one', 'client_id' => $client->getKey(), 'auth_token' => $first])
        ->assertRedirect()->headers->get('Location');
    expect(mcQuery($location)['error'] ?? null)->toBe('access_denied')
        ->and(mcQuery($location)['state'])->toBe('tab-one');
});
