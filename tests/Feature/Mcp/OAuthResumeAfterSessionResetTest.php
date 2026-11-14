<?php

use App\Models\User;
use App\OAuth\AuthorizeResume;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Laravel\Passport\Client;
use Laravel\Passport\Token;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\User as GoogleUser;

/*
 * Connecting from ChatGPT / Claude and switching account on the way — sign
 * out, "Use another account", the frontend's own sign-out, a fresh login,
 * Google — empties the session that held the pending authorize request.
 * The person must still land on the consent screen for the SAME request
 * (state, PKCE challenge, redirect_uri) and Allow must issue the code to
 * the account signed in now; a request that is really gone says so plainly.
 */

/** Laravel skips CSRF in tests; this one does not (and sends no Sec-Fetch-Site). */
class StrictForgeryForResumeTest extends PreventRequestForgery
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
    ]);
});

/** @return array{0: string, 1: array<string, string>, 2: string} authorize URL (absolute), its query, PKCE verifier */
function resumeAuthorizeUrl(Client $client): array
{
    [$verifier, $challenge] = pkcePair();
    $query = [
        'client_id' => (string) $client->getKey(),
        'redirect_uri' => $client->redirect_uris[0],
        'response_type' => 'code',
        'scope' => 'read draft',
        'state' => 'state-from-chatgpt',
        'code_challenge' => $challenge,
        'code_challenge_method' => 'S256',
    ];

    return [url('/oauth/authorize').'?'.http_build_query($query), $query, $verifier];
}

/** @return array<string, string> */
function resumeQueryOf(string $url): array
{
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    return $query;
}

/** The ?next= path the login was given (on the frontend), decoded. */
function resumeNextOf(string $loginUrl): string
{
    expect($loginUrl)->toStartWith('https://app.creatif.space/login?next=');

    return resumeQueryOf($loginUrl)['next'];
}

/** A redirect to /oauth/authorize carrying exactly this query (Laravel sorts the parameters). */
function resumeAssertBackTo(mixed $response, array $query): string
{
    $location = (string) $response->assertRedirect()->headers->get('Location');
    expect(parse_url($location, PHP_URL_PATH))->toBe('/oauth/authorize')
        ->and(resumeQueryOf($location))->toEqual($query);

    return $location;
}

/** The hidden "resume" field of the consent screen just rendered. */
function resumeFieldOf(string $html): string
{
    expect(preg_match('/name="resume" value="([^"]+)"/', $html, $m))->toBe(1);

    return html_entity_decode($m[1]);
}

/** Allow on the consent screen currently in the session; returns the code. */
function resumeAllow(mixed $test, Client $client, array $query): string
{
    $approve = $test->post('/oauth/authorize', [
        'state' => $query['state'], 'client_id' => $client->getKey(), 'auth_token' => session('authToken'),
    ])->assertRedirect();
    $location = (string) $approve->headers->get('Location');
    expect($location)->toStartWith($query['redirect_uri'].'?');
    $returned = resumeQueryOf($location);
    expect($returned['state'])->toBe($query['state']);

    return $returned['code'];
}

function resumeRedeem(mixed $test, Client $client, array $query, string $verifier, string $code): void
{
    $test->post('/oauth/token', [
        'grant_type' => 'authorization_code',
        'client_id' => $client->getKey(),
        'redirect_uri' => $query['redirect_uri'],
        'code_verifier' => $verifier,
        'code' => $code,
    ])->assertOk()->assertJsonStructure(['access_token', 'refresh_token']);
}

it('reproduces the bug: consent for A, sign out (session reset), sign in as B — lands on the consent for the same request, Allow gives B the code', function () {
    $client = mcpClient('https://chatgpt.com/connector/oauth/qPty7QlyU5Qp');
    $a = User::factory()->create(['email' => 'alice@example.test']);
    $b = User::factory()->create(['email' => 'bobby@example.test']);
    [$url, $query, $verifier] = resumeAuthorizeUrl($client);

    $consent = $this->actingAs($a, 'web')->get($url)->assertOk()->assertSee('al***@example.test');
    $oldToken = session('authToken');
    $resume = resumeFieldOf($consent->getContent());

    // Signed out from the frontend: the whole session is invalidated.
    $this->post('/auth/logout')->assertNoContent();
    $this->assertGuest('web');

    // The old consent tab's Allow: not a dead end — on to the login, with the request.
    $login = (string) $this->post('/oauth/authorize', [
        'state' => $query['state'], 'client_id' => $client->getKey(), 'auth_token' => $oldToken, 'resume' => $resume,
    ])->assertRedirect()->headers->get('Location');
    $next = resumeNextOf($login);
    expect(Token::query()->count())->toBe(0);

    // Login regenerates the session (and may empty it): the ?next= alone brings B back.
    $this->flushSession();
    $back = resumeAssertBackTo($this->actingAs($b, 'web')->get($next), $query);

    $this->get($back)->assertOk()->assertSee('bo***@example.test')->assertDontSee('al***@example.test');
    resumeRedeem($this, $client, $query, $verifier, resumeAllow($this, $client, $query));

    expect(Token::query()->where('user_id', $b->id)->count())->toBe(1)
        ->and(Token::query()->where('user_id', $a->id)->count())->toBe(0);
});

it('keeps the request through "Not you? Use another account" even when the session is emptied on the way', function () {
    $client = mcpClient();
    $a = User::factory()->create();
    $b = User::factory()->create();
    [$url, $query, $verifier] = resumeAuthorizeUrl($client);

    $this->actingAs($a, 'web')->get($url)->assertOk();
    $back = (string) $this->post('/oauth/switch-account')->assertRedirect()->headers->get('Location');
    expect(resumeQueryOf($back))->toEqual($query);
    $this->assertGuest('web');

    $next = resumeNextOf((string) $this->get($back)->assertRedirect()->headers->get('Location'));

    // On the login page they sign out / "use another account" again: session gone.
    $this->flushSession();
    $this->get(resumeAssertBackTo($this->actingAs($b, 'web')->get($next), $query))->assertOk();
    resumeRedeem($this, $client, $query, $verifier, resumeAllow($this, $client, $query));

    expect(Token::query()->where('user_id', $b->id)->count())->toBe(1)
        ->and(Token::query()->where('user_id', $a->id)->count())->toBe(0);
});

it('keeps the request for "Use another account" from a consent page whose session was already reset', function () {
    $client = mcpClient();
    $a = User::factory()->create();
    [$url, $query] = resumeAuthorizeUrl($client);

    $resume = resumeFieldOf($this->actingAs($a, 'web')->get($url)->assertOk()->getContent());
    $this->flushSession();

    // No pending request left in the session: the form's own copy is used.
    resumeAssertBackTo($this->post('/oauth/switch-account', ['resume' => $resume]), $query);
    $this->assertGuest('web');
});

it('shows the same request again for a stale CSRF token instead of "Page Expired", and changes nothing', function () {
    $this->app->bind(PreventRequestForgery::class, StrictForgeryForResumeTest::class);
    $client = mcpClient();
    $a = User::factory()->create();
    [$url, $query] = resumeAuthorizeUrl($client);

    $consent = $this->actingAs($a, 'web')->get($url)->assertOk();
    $resume = resumeFieldOf($consent->getContent());
    $authToken = session('authToken');

    // Allow from a tab whose session token changed (signed in again elsewhere).
    resumeAssertBackTo($this->post('/oauth/authorize', [
        '_token' => 'stale', 'state' => $query['state'], 'client_id' => $client->getKey(), 'auth_token' => $authToken, 'resume' => $resume,
    ]), $query);
    expect(Token::query()->count())->toBe(0);

    // "Use another account" with a stale token: back to the request, still signed in.
    resumeAssertBackTo($this->post('/oauth/switch-account', ['_token' => 'stale', 'resume' => $resume]), $query);
    $this->assertAuthenticatedAs($a, 'web');

    // Without a usable copy of the request: the plain expired page.
    $this->post('/oauth/switch-account', ['_token' => 'stale', 'resume' => 'forged.1.x'])
        ->assertStatus(419)->assertSee('This sign-in link expired')->assertSee('press Connect again');
});

it('brings a fresh login (not signed in at the start) back to the consent screen, even if the session was emptied', function () {
    $client = mcpClient();
    $b = User::factory()->create();
    [$url, $query, $verifier] = resumeAuthorizeUrl($client);

    $next = resumeNextOf((string) $this->get($url)->assertRedirect()->headers->get('Location'));
    expect($next)->toStartWith('/oauth/resume?r=');

    $this->flushSession();
    $this->get(resumeAssertBackTo($this->actingAs($b, 'web')->get($next), $query))->assertOk();
    resumeRedeem($this, $client, $query, $verifier, resumeAllow($this, $client, $query));

    expect(Token::query()->where('user_id', $b->id)->count())->toBe(1);
});

it('brings a Google sign-in back to the consent screen for the same request', function () {
    $client = mcpClient();
    $b = User::factory()->create(['google_id' => 'g-resume-b', 'email' => 'gbobby@example.test']);
    [$url, $query, $verifier] = resumeAuthorizeUrl($client);

    $next = resumeNextOf((string) $this->get($url)->assertRedirect()->headers->get('Location'));

    // The frontend's "Continue with Google" carries the same ?next=.
    $this->get('/auth/google/redirect?'.http_build_query(['next' => $next]))->assertRedirect();

    $google = (new GoogleUser)->map(['id' => 'g-resume-b', 'name' => 'B', 'email' => 'gbobby@example.test', 'avatar' => null]);
    $driver = Mockery::mock(GoogleProvider::class);
    $driver->shouldReceive('user')->andReturn($google);
    Socialite::shouldReceive('driver')->with('google')->andReturn($driver);

    $this->get('/auth/google/callback?code=abc')->assertRedirect('https://app.creatif.space'.$next);
    $this->assertAuthenticatedAs($b, 'web');

    $this->get(resumeAssertBackTo($this->get($next), $query))->assertOk()->assertSee('gb***@example.test');
    resumeRedeem($this, $client, $query, $verifier, resumeAllow($this, $client, $query));

    expect(Token::query()->where('user_id', $b->id)->count())->toBe(1);
});

it('says plainly when a request is really gone: go back and press Connect again', function () {
    $client = mcpClient();
    $user = User::factory()->create();
    [$url, $query] = resumeAuthorizeUrl($client);

    // A resume link older than an hour, or one that was tampered with.
    $old = AuthorizeResume::token($url, time() - AuthorizeResume::TTL_SECONDS - 5);
    $this->actingAs($user, 'web')->get('/oauth/resume?'.http_build_query(['r' => $old]))
        ->assertStatus(400)->assertSee('This sign-in link expired')->assertSee('press Connect again');
    $this->get('/oauth/resume?r=forged.9999999999.abc')->assertStatus(400)->assertSee('This sign-in link expired');

    // Allow with an unknown token, nothing pending and no copy of the request.
    $this->post('/oauth/authorize', ['state' => $query['state'], 'client_id' => $client->getKey(), 'auth_token' => 'gone'])
        ->assertStatus(400)->assertSee('This sign-in link expired')->assertSee('ChatGPT, Claude');
    expect(Token::query()->count())->toBe(0);
});

it('signs only authorize URLs on this host, and refuses anything altered', function () {
    expect(AuthorizeResume::token('https://evil.test/oauth/authorize?x=1'))->toBeNull()
        ->and(AuthorizeResume::token(url('/oauth/token').'?x=1'))->toBeNull();

    $url = url('/oauth/authorize').'?client_id=1&state=s';
    $token = (string) AuthorizeResume::token($url);
    expect(AuthorizeResume::url($token))->toBe($url);

    [$payload, $expires, $sig] = explode('.', $token);
    $other = rtrim(strtr(base64_encode('client_id=2&state=s'), '+/', '-_'), '=');
    expect(AuthorizeResume::url("{$other}.{$expires}.{$sig}"))->toBeNull()
        ->and(AuthorizeResume::url("{$payload}.".($expires + 999).".{$sig}"))->toBeNull()
        ->and(AuthorizeResume::url(null))->toBeNull()
        ->and(AuthorizeResume::url('a.b'))->toBeNull();
});
