<?php

use App\Enums\UserStatus;
use App\Models\User;
use App\Services\Analytics;
use App\Support\GoogleIdToken;
use Firebase\JWT\JWT;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\User as GoogleUser;

/*
 * POST /auth/google/native — the Android app's Google Sign-In. The shell
 * gets an ID token from Credential Manager and the WebView posts it here.
 * Tokens are signed with a key made for the test; Google's JWKS endpoint
 * is faked to publish it.
 */

const GN_CLIENT_ID = 'web-client.apps.googleusercontent.com';

beforeEach(function () {
    config([
        'app.frontend_url' => 'https://app.test',
        'services.google.client_id' => GN_CLIENT_ID,
        'services.google.native_extra_audiences' => '',
    ]);
    Cache::flush();
    $this->analytics = Analytics::fake();

    $this->gnKey = gnRsaKey();
    $this->gnJwks = ['keys' => [gnJwk($this->gnKey, 'kid-1')]];
    $this->gnJwksStatus = 200;
    Http::fake([GoogleIdToken::JWKS_URL => fn () => Http::response($this->gnJwks, $this->gnJwksStatus, ['Cache-Control' => 'public, max-age=21600'])]);
});

/** @return array{private: string, n: string, e: string} */
function gnRsaKey(): array
{
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $private);
    $details = openssl_pkey_get_details($key)['rsa'];

    return ['private' => $private, 'n' => JWT::urlsafeB64Encode($details['n']), 'e' => JWT::urlsafeB64Encode($details['e'])];
}

function gnJwk(array $key, string $kid): array
{
    return ['kty' => 'RSA', 'alg' => 'RS256', 'use' => 'sig', 'kid' => $kid, 'n' => $key['n'], 'e' => $key['e']];
}

/** A Google ID token, as Credential Manager hands it over, with $claims overriding. */
function gnToken(array $claims = [], string $kid = 'kid-1', ?array $key = null): string
{
    $now = now()->getTimestamp();

    return JWT::encode([
        'iss' => 'https://accounts.google.com',
        'azp' => 'android-client.apps.googleusercontent.com',
        'aud' => GN_CLIENT_ID,
        'sub' => 'g-100',
        'email' => 'rani@example.com',
        'email_verified' => true,
        'name' => 'Rani',
        'picture' => 'https://lh3.googleusercontent.com/a/rani',
        'iat' => $now,
        'exp' => $now + 3600,
        ...$claims,
    ], ($key ?? test()->gnKey)['private'], 'RS256', $kid);
}

function gnPost(string $token, ?string $next = null)
{
    return test()->postJson('/auth/google/native', array_filter(['id_token' => $token, 'next' => $next]));
}

it('sits in the web group (session + CSRF) beside the callback, throttled', function () {
    $route = Route::getRoutes()->getByName('auth.google.native');

    expect($route->methods())->toContain('POST')
        ->and($route->gatherMiddleware())->toContain('web', 'throttle:10,1')
        ->and(Route::getRoutes()->getByName('auth.google.callback')->gatherMiddleware())->toContain('web');
});

describe('a valid token', function () {
    it('creates a new account, starts the session and tells the app where to go', function () {
        $response = gnPost(gnToken(), '/spaces')
            ->assertOk()
            ->assertExactJson(['redirect' => 'https://app.test/spaces'])
            ->assertCookie(config('session.cookie'));

        $user = User::query()->sole();
        expect($user->google_id)->toBe('g-100')
            ->and($user->email)->toBe('rani@example.com')
            ->and($user->name)->toBe('Rani')
            ->and($user->email_verified_at)->not->toBeNull();
        $this->assertAuthenticatedAs($user);

        $event = $this->analytics->captured('user_signed_up')[0];
        expect($event['distinct_id'])->toBe($user->ulid)
            ->and($event['properties'])->toMatchArray(['method' => 'google', 'platform' => 'android_app']);
        $this->analytics->assertCapturedOnce('user_signed_up');
    });

    it('lands on Home without a next, or with one that leaves our origin', function (?string $next) {
        gnPost(gnToken(), $next)->assertOk()->assertExactJson(['redirect' => 'https://app.test/home']);
    })->with([null, 'https://evil.test/x', '//evil.test']);

    it('signs a returning user in by google_id, with no signup event', function () {
        $user = User::factory()->create(['email' => 'old@example.com', 'google_id' => 'g-100']);

        gnPost(gnToken())->assertOk();

        $this->assertAuthenticatedAs($user);
        expect(User::query()->count())->toBe(1);
        $this->analytics->assertNotCaptured('user_signed_up');
    });

    it('links an existing account by email on its first Google sign-in', function () {
        $user = User::factory()->create(['email' => 'rani@example.com', 'google_id' => null]);

        gnPost(gnToken())->assertOk();

        $this->assertAuthenticatedAs($user);
        expect($user->fresh()->google_id)->toBe('g-100');
        $this->analytics->assertNotCaptured('user_signed_up');
    });

    it('accepts an extra audience from GOOGLE_NATIVE_EXTRA_AUDIENCES', function () {
        config(['services.google.native_extra_audiences' => 'other.apps.googleusercontent.com, extra.apps.googleusercontent.com']);

        gnPost(gnToken(['aud' => 'extra.apps.googleusercontent.com']))->assertOk();
    });

    it('fetches the key set once and serves later logins from cache', function () {
        gnPost(gnToken())->assertOk();
        auth()->logout();
        gnPost(gnToken())->assertOk();

        Http::assertSentCount(1);
    });
});

describe('a token we refuse', function () {
    it('answers 422 invalid_token, starts no session and logs the reason, never the token', function (Closure $token, string $reason) {
        $jwt = $token();
        Log::spy();

        gnPost($jwt)
            ->assertStatus(422)
            ->assertExactJson(['message' => 'Google sign-in could not be verified. Try again.', 'reason' => 'invalid_token']);

        $this->assertGuest();
        expect(User::query()->count())->toBe(0);
        Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context) use ($reason, $jwt) {
            return $message === 'auth.google_native_invalid_token'
                && str_contains($context['reason'], $reason)
                && ($jwt === '' || ! str_contains(json_encode($context), $jwt));
        })->once();
    })->with([
        'wrong audience' => [fn () => gnToken(['aud' => 'someone-else.apps.googleusercontent.com']), 'wrong aud'],
        'wrong issuer' => [fn () => gnToken(['iss' => 'https://evil.test']), 'wrong iss'],
        'expired' => [fn () => gnToken(['iat' => now()->subHours(2)->getTimestamp(), 'exp' => now()->subMinutes(5)->getTimestamp()]), 'Expired'],
        'issued in the future' => [fn () => gnToken(['iat' => now()->addMinutes(10)->getTimestamp()]), 'iat'],
        'bad signature' => [fn () => gnToken([], 'kid-1', gnRsaKey()), 'Signature'],
        'unknown kid' => [fn () => gnToken([], 'kid-rotated-away'), 'unknown kid'],
        'email not verified' => [fn () => gnToken(['email_verified' => false]), 'email not verified'],
        'no email' => [fn () => gnToken(['email' => null]), 'no email'],
        'tampered payload' => [fn () => (function () {
            [$h, , $s] = explode('.', gnToken());

            return $h.'.'.JWT::urlsafeB64Encode(json_encode(['sub' => 'g-admin', 'aud' => GN_CLIENT_ID])).'.'.$s;
        })(), 'Signature'],
        'not a JWT' => [fn () => 'nope', 'malformed'],
        'missing' => [fn () => '', 'missing'],
    ]);

    it('allows a token one minute past exp (leeway)', function () {
        gnPost(gnToken(['exp' => now()->subSeconds(30)->getTimestamp()]))->assertOk();
    });

    it('refreshes the key set once for an unknown kid, and accepts a rotated-in key', function () {
        gnPost(gnToken())->assertOk(); // caches kid-1
        auth()->logout();

        $new = gnRsaKey();
        $this->gnJwks = ['keys' => [gnJwk($new, 'kid-2')]];

        gnPost(gnToken([], 'kid-2', $new))->assertOk();
        Http::assertSentCount(2);
    });

    it('refreshes at most once for a burst of unknown kids', function () {
        gnPost(gnToken())->assertOk();
        auth()->logout();

        gnPost(gnToken([], 'kid-x'))->assertStatus(422);
        gnPost(gnToken([], 'kid-y'))->assertStatus(422);

        Http::assertSentCount(2);
        Http::assertSent(fn (HttpRequest $r) => $r->url() === GoogleIdToken::JWKS_URL);
    });

    it('says google_unavailable when the key set cannot be fetched', function () {
        $this->gnJwksStatus = 500;

        gnPost(gnToken())->assertStatus(503)->assertJson(['reason' => 'google_unavailable']);
        $this->assertGuest();
    });
});

describe('accounts that must not sign in', function () {
    it('refuses a staff address matched by email only, links nothing, and logs it', function () {
        $staff = User::factory()->create(['email' => 'rani@example.com', 'is_staff' => true, 'google_id' => null]);
        Log::spy();

        gnPost(gnToken())->assertStatus(403)->assertJson(['reason' => 'google_not_linked']);

        $this->assertGuest();
        expect($staff->fresh()->google_id)->toBeNull();
        Log::shouldHaveReceived('warning')->with('auth.google_staff_link_refused', Mockery::any())->once();
    });

    it('signs staff in when the google_id matches', function () {
        $staff = User::factory()->create(['email' => 'rani@example.com', 'is_staff' => true, 'google_id' => 'g-100']);

        gnPost(gnToken())->assertOk();
        $this->assertAuthenticatedAs($staff);
    });

    it('refuses a suspended account', function () {
        User::factory()->create(['email' => 'rani@example.com', 'google_id' => 'g-100', 'status' => UserStatus::Suspended]);

        gnPost(gnToken())->assertStatus(403)->assertJson(['reason' => 'suspended']);
        $this->assertGuest();
    });

    it('throttles at 10 a minute per IP', function () {
        foreach (range(1, 10) as $i) {
            gnPost('nope')->assertStatus(422);
        }

        gnPost('nope')->assertStatus(429);
    });
});

describe('the web callback after the refactor', function () {
    beforeEach(function () {
        $google = (new GoogleUser)->map(['id' => 'g-web', 'name' => null, 'nickname' => 'rani_n', 'email' => 'web@example.com', 'avatar' => 'https://lh3/a']);
        $driver = Mockery::mock(GoogleProvider::class);
        $driver->shouldReceive('user')->andReturn($google);
        Socialite::shouldReceive('driver')->with('google')->andReturn($driver);
    });

    it('still creates the account, lands on next, and records a web signup', function () {
        $this->withSession(['next_path' => '/spaces', 'referral_code' => 'NOPE'])
            ->get('/auth/google/callback?code=abc')
            ->assertRedirect('https://app.test/spaces');

        $user = User::query()->sole();
        expect($user->google_id)->toBe('g-web')
            ->and($user->name)->toBe('rani_n')
            ->and($user->avatar_url)->toBe('https://lh3/a')
            ->and($user->email_verified_at)->not->toBeNull();
        $this->assertAuthenticatedAs($user);

        $props = $this->analytics->captured('user_signed_up')[0]['properties'];
        expect($props['method'])->toBe('google')->and($props)->not->toHaveKey('platform');
    });

    it('still clears an unverified password when linking by email', function () {
        $user = User::factory()->create(['email' => 'web@example.com', 'google_id' => null, 'email_verified_at' => null, 'password' => 'secret-pass']);

        $this->get('/auth/google/callback?code=abc')->assertRedirect('https://app.test/home');

        $user->refresh();
        expect($user->google_id)->toBe('g-web')
            ->and($user->password)->toBeNull()
            ->and($user->email_verified_at)->not->toBeNull();
    });

    it('still sends a suspended account to the login page', function () {
        User::factory()->create(['email' => 'web@example.com', 'status' => UserStatus::Suspended]);

        $this->get('/auth/google/callback?code=abc')->assertRedirect('https://app.test/login?error=suspended');
        $this->assertGuest();
    });
});
