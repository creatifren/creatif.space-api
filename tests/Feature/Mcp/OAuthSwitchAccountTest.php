<?php

use App\Models\Plan;
use App\Models\TeamMember;
use App\Models\User;
use App\Support\McpConsent;
use App\Support\Workspace;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Laravel\Passport\Client;
use Laravel\Passport\Token;

/*
 * "Not you? Use another account" on the MCP consent screen: sign out of
 * Creatif Space only, come back to the same authorize request, finish it as
 * the other account — and never issue anything to the first one.
 */

/** Laravel skips CSRF in tests; this one does not. */
class StrictForgeryForSwitchTest extends PreventRequestForgery
{
    protected function runningUnitTests(): bool
    {
        return false;
    }
}

beforeEach(function () {
    ensurePassportKeys();
    config([
        'app.url' => 'https://api.creatif.space',
        'app.frontend_url' => 'https://app.creatif.space',
        'mcp.resource_url' => 'https://mcp.creatif.space/mcp',
    ]);
});

/**
 * @return array{0: string, 1: array<string, string>, 2: string} the authorize URL, its query, and the PKCE verifier
 */
function switchAuthorizeUrl(Client $client, string $scope = 'read draft'): array
{
    [$verifier, $challenge] = pkcePair();
    $query = [
        'client_id' => (string) $client->getKey(),
        'redirect_uri' => $client->redirect_uris[0],
        'response_type' => 'code',
        'scope' => $scope,
        'state' => 'state-from-claude',
        'code_challenge' => $challenge,
        'code_challenge_method' => 'S256',
    ];

    return ['/oauth/authorize?'.http_build_query($query), $query, $verifier];
}

/** @return array<string, string> */
function switchQueryOf(string $url): array
{
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    return $query;
}

it('shows who is signed in, masked, with the workspace and the permissions', function () {
    $client = mcpClient();
    $user = User::factory()->create(['name' => 'Sam Rivera', 'email' => 'sam.rivera@example.com']);
    [$url] = switchAuthorizeUrl($client, 'read draft approve publish');

    $this->actingAs($user, 'web')->get($url)
        ->assertOk()
        ->assertSeeInOrder(['Signed in as', 'Sam Rivera', 'sa***@example.com', 'Workspace', 'Sam Rivera'])
        ->assertDontSee('sam.rivera@example.com')
        ->assertSee('Not you?')
        ->assertSee('Use another account')
        ->assertSee(route('oauth.switch-account'), false)
        ->assertSeeInOrder(['Read', 'Draft', 'Approve', 'Publish'])
        ->assertSee(config('mcp.scopes.publish'));
});

it('names the team owner\'s workspace for a member', function () {
    // A membership counts only on a plan with seats for it (Team).
    $owner = User::factory()->create(['name' => 'Kopi Senja']);
    $owner->subscriptions()->create([
        'plan_id' => Plan::query()->where('key', 'team')->value('id'),
        'billing_period' => 'monthly', 'seats' => 3, 'status' => 'active',
    ])->forceFill(['current_period_start' => now()->subDays(10), 'current_period_end' => now()->addDays(20)])->save();
    $member = User::factory()->create(['name' => 'Dewi', 'email' => 'dewi@example.test']);
    TeamMember::factory()->active($member)->create(['owner_id' => $owner->id, 'role' => 'editor']);
    Workspace::forget();

    expect(McpConsent::workspaceName($member))->toBe('Kopi Senja')
        ->and(McpConsent::workspaceName($owner))->toBe('Kopi Senja');
});

it('masks an address to its first letters and domain', function (string $email, string $masked) {
    expect(McpConsent::maskEmail($email))->toBe($masked);
})->with([
    ['sam.rivera@example.com', 'sa***@example.com'],
    ['ab@x.test', 'a***@x.test'],
    ['a@x.test', 'a***@x.test'],
    ['not-an-email', '***'],
]);

it('switches account and completes the original request for the new account only', function () {
    $client = mcpClient();
    $old = User::factory()->create(['name' => 'Old', 'email' => 'old@example.test']);
    $new = User::factory()->create(['name' => 'New', 'email' => 'new@example.test']);
    [$url, $query, $verifier] = switchAuthorizeUrl($client);

    // Consent shown to the account that happened to be signed in.
    $this->actingAs($old, 'web')->get($url)->assertOk()->assertSee('ol***@example.test');
    $oldAuthToken = session('authToken');
    expect($oldAuthToken)->toBeString();

    // Not you? → signed out of Creatif Space, back to the very same request.
    $switch = $this->post('/oauth/switch-account')->assertRedirect();
    $back = (string) $switch->headers->get('Location');
    expect(parse_url($back, PHP_URL_PATH))->toBe('/oauth/authorize')
        ->and(switchQueryOf($back))->toEqual($query);
    $this->assertGuest('web');
    expect(session('authToken'))->toBeNull()
        ->and(session('authRequest'))->toBeNull()
        ->and(session('auth.select_account'))->toBeTrue();

    // As a guest, the same URL goes to the login and is remembered.
    $login = (string) $this->get($back)->assertRedirect()->headers->get('Location');
    expect($login)->toStartWith('https://app.creatif.space/login?next='.urlencode('/oauth/resume?r='));
    $intended = (string) session('oauth.intended');
    expect(switchQueryOf($intended))->toEqual($query);

    // The old account's consent form is dead: guest → login, nothing issued.
    $this->post('/oauth/authorize', [
        'state' => $query['state'], 'client_id' => $client->getKey(), 'auth_token' => $oldAuthToken,
    ])->assertRedirect();
    expect(Token::query()->count())->toBe(0);

    // Signed in as the other account, back to the consent screen for them.
    $this->actingAs($new, 'web')->get('/oauth/resume')->assertRedirect($intended);
    $this->get($intended)->assertOk()->assertSee('ne***@example.test')->assertDontSee('ol***@example.test');

    $approve = $this->post('/oauth/authorize', [
        'state' => $query['state'], 'client_id' => $client->getKey(), 'auth_token' => session('authToken'),
    ])->assertRedirect();
    $location = (string) $approve->headers->get('Location');
    expect($location)->toStartWith($query['redirect_uri'].'?');
    $returned = switchQueryOf($location);
    expect($returned['state'])->toBe('state-from-claude');

    // The client's original PKCE verifier still redeems the code.
    $this->post('/oauth/token', [
        'grant_type' => 'authorization_code',
        'client_id' => $client->getKey(),
        'redirect_uri' => $query['redirect_uri'],
        'code_verifier' => $verifier,
        'code' => $returned['code'],
    ])->assertOk()->assertJsonStructure(['access_token', 'refresh_token']);

    expect(Token::query()->where('user_id', $new->id)->count())->toBe(1)
        ->and(Token::query()->where('user_id', $old->id)->count())->toBe(0);
});

it('never issues a code to the account the consent screen was shown to once the session changed hands', function () {
    $client = mcpClient();
    $old = User::factory()->create();
    $new = User::factory()->create();
    [$url, $query] = switchAuthorizeUrl($client);

    $this->actingAs($old, 'web')->get($url)->assertOk();
    $authToken = session('authToken');

    // Signed in as someone else (another tab) without going through "Not you?".
    $this->actingAs($new, 'web')
        ->post('/oauth/authorize', ['state' => $query['state'], 'client_id' => $client->getKey(), 'auth_token' => $authToken])
        ->assertRedirect();

    $location = (string) $this->post('/oauth/authorize', [
        'state' => $query['state'], 'client_id' => $client->getKey(), 'auth_token' => $authToken,
    ])->headers->get('Location');

    expect($location)->not->toContain('code=');
    expect(session('authRequest'))->toBeNull();
    expect(Token::query()->count())->toBe(0);
});

it('sends the new account back to the consent screen for itself after a hand-over', function () {
    $client = mcpClient();
    [$url, $query] = switchAuthorizeUrl($client);

    $this->actingAs(User::factory()->create(), 'web')->get($url)->assertOk();
    $authToken = session('authToken');

    $response = $this->actingAs(User::factory()->create(), 'web')
        ->post('/oauth/authorize', ['state' => $query['state'], 'client_id' => $client->getKey(), 'auth_token' => $authToken])
        ->assertRedirect();

    $location = (string) $response->headers->get('Location');
    expect(parse_url($location, PHP_URL_PATH))->toBe('/oauth/authorize')
        ->and(switchQueryOf($location))->toEqual($query);
});

it('requires a CSRF token to switch account', function () {
    $this->app->bind(PreventRequestForgery::class, StrictForgeryForSwitchTest::class);
    $client = mcpClient();
    $user = User::factory()->create();
    [$url] = switchAuthorizeUrl($client);

    $this->actingAs($user, 'web')->get($url)->assertOk();

    $this->post('/oauth/switch-account')->assertStatus(419);
    $this->post('/oauth/switch-account', ['_token' => 'forged'])->assertStatus(419);
    $this->assertAuthenticatedAs($user, 'web');
    expect(session('authToken'))->toBeString();

    $this->post('/oauth/switch-account', ['_token' => session()->token()])->assertRedirect();
    $this->assertGuest('web');
});

it('only ever returns to a remembered authorize URL', function () {
    $this->actingAs(User::factory()->create(), 'web')
        ->withSession(['oauth.pending' => ['url' => 'https://evil.test/oauth/authorize?x=1']])
        ->post('/oauth/switch-account', ['url' => 'https://evil.test/'])
        ->assertRedirect('https://app.creatif.space/login');
    $this->assertGuest('web');
});

it('asks Google to show the account chooser once after a switch', function () {
    $this->withSession(['auth.select_account' => true]);
    $chooser = (string) $this->get('/auth/google/redirect')->headers->get('Location');
    // Once only: the flag is spent.
    $first = (string) $this->get('/auth/google/redirect')->headers->get('Location');

    expect(switchQueryOf($chooser)['prompt'] ?? null)->toBe('select_account')
        ->and(switchQueryOf($first)['prompt'] ?? null)->toBeNull();
});

it('leaves approve and deny as they were', function () {
    $client = mcpClient();
    $user = User::factory()->create();
    [$url, $query] = switchAuthorizeUrl($client);

    $this->actingAs($user, 'web')->get($url)->assertOk();
    $deny = $this->delete('/oauth/authorize', [
        'state' => $query['state'], 'client_id' => $client->getKey(), 'auth_token' => session('authToken'),
    ])->assertRedirect();
    expect(switchQueryOf((string) $deny->headers->get('Location')))
        ->toMatchArray(['error' => 'access_denied', 'state' => 'state-from-claude']);

    $tokens = oauthTokens($this, $user, $client, 'read draft');
    expect($tokens['access_token'])->toBeString()
        ->and(Token::query()->where('user_id', $user->id)->count())->toBe(1);
});
