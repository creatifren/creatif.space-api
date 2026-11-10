<?php

use App\Enums\SocialPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\OutstandService;
use Illuminate\Support\Facades\Http;

/*
 * Every non-success way back from a connect lands on the app's connect
 * screen with connect=failed, the network and a reason — never on a
 * provider page, a JSON body or a 500. All eight OAuth networks; Bluesky
 * has no OAuth round trip (it connects inside POST /auth-url).
 */

beforeEach(function () {
    config([
        'services.social.provider' => 'outstand',
        'services.outstand.key' => 'sk_test',
        'services.outstand.base_url' => 'https://api.outstand.test/v1',
        'app.frontend_url' => 'https://app.test',
    ]);
});

const OAUTH_NETWORKS = ['instagram', 'facebook', 'x', 'tiktok', 'linkedin', 'threads', 'youtube', 'pinterest'];

/** The Location's query, after asserting it is the app's connect screen. */
function connectReturn($response): array
{
    $response->assertRedirect();
    $location = (string) $response->headers->get('Location');
    expect($location)->toStartWith('https://app.test/social/connect?');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $q);

    return $q;
}

function returnUrl(string $state, array $query = []): string
{
    return '/api/v1/webhooks/outstand?'.http_build_query(['state' => $state] + $query);
}

describe('every failure comes back to the app, per network', function () {
    it('cancel, deny, expiry and provider errors each name a reason', function (string $network, array $query, string $reason) {
        Http::fake(); // nothing may be synced on a refusal
        $user = User::factory()->create();

        $q = connectReturn($this->get(returnUrl(OutstandService::seal($user->ulid, $network), $query)));

        expect($q)->toMatchArray([
            'connect' => 'failed', 'network' => $network, 'reason' => $reason,
            'social' => 'connected', 'isSuccess' => 'false',
        ])->and($q['error'])->toBeString()->not->toBe('');
        Http::assertNothingSent();
        expect(OutstandService::attempt($user->ulid))->toMatchArray(['status' => 'failed', 'reason' => $reason, 'network' => $network]);
    })->with(OAUTH_NETWORKS)->with([
        'cancelled' => [['error' => 'user_cancelled', 'error_description' => 'User cancelled the login'], 'cancelled'],
        'denied (OAuth)' => [['error' => 'access_denied', 'error_reason' => 'user_denied'], 'denied'],
        'denied (permissions)' => [['error' => 'Permissions error'], 'denied'],
        'expired session' => [['error' => 'Session expired'], 'expired'],
        'timed out' => [['error' => 'Request timed out'], 'expired'],
        'provider error' => [['error' => 'server_error', 'error_description' => 'Something broke'], 'error'],
        'bare isSuccess=false' => [['isSuccess' => 'false'], 'error'],
        'bare success=false' => [['success' => 'false'], 'error'],
    ]);

    it('an expired or unknown state says expired, with the network when Outstand names it', function (string $network) {
        $q = connectReturn($this->get(returnUrl(str_repeat('a', 40), ['network' => $network])));

        expect($q)->toMatchArray(['connect' => 'failed', 'network' => $network, 'reason' => 'expired']);
    })->with(OAUTH_NETWORKS);

    it('a forged, mangled or missing state is invalid_state', function (string $network) {
        foreach ([returnUrl('../../etc', ['network' => $network]), '/api/v1/webhooks/outstand?network='.$network] as $url) {
            expect(connectReturn($this->get($url)))->toMatchArray(['connect' => 'failed', 'network' => $network, 'reason' => 'invalid_state']);
        }
    })->with(OAUTH_NETWORKS);

    it('a state used once answers the same on a refresh, never a second sync', function (string $network) {
        $user = User::factory()->create();
        $state = OutstandService::seal($user->ulid, $network);

        $first = $this->get(returnUrl($state, ['error' => 'access_denied']))->headers->get('Location');
        Http::fake();
        $again = $this->get(returnUrl($state))->headers->get('Location');

        expect($again)->toBe($first);
        Http::assertNothingSent();
    })->with(OAUTH_NETWORKS);

    it('an Outstand outage on the return is unconfirmed, not a 500', function (string $network) {
        Http::fake(['api.outstand.test/*' => Http::response('', 503)]);
        $user = User::factory()->create();

        expect(connectReturn($this->get(returnUrl(OutstandService::seal($user->ulid, $network)))))
            ->toMatchArray(['connect' => 'failed', 'network' => $network, 'reason' => 'unconfirmed']);
    })->with(OAUTH_NETWORKS);

    it('a success carries connect=success and the network', function (string $network) {
        $user = User::factory()->create();
        Http::fake(['api.outstand.test/v1/social-accounts*' => Http::response(['success' => true, 'data' => [[
            'id' => 'acc_'.$network, 'network' => $network, 'username' => 'studio', 'tenant_id' => $user->ulid, 'isActive' => 1,
        ]], 'count' => 1, 'total' => 1])]);

        expect(connectReturn($this->get(returnUrl(OutstandService::seal($user->ulid, $network)))))
            ->toMatchArray(['connect' => 'success', 'network' => $network, 'social' => 'connected', 'count' => '1']);
        expect(OutstandService::attempt($user->ulid))->toMatchArray(['status' => 'success', 'network' => $network]);
    })->with(OAUTH_NETWORKS);
});

it('a denial never reads as success because other accounts are already connected', function () {
    $user = User::factory()->create();
    SocialAccount::factory()->for($user)->create(['platform' => 'facebook', 'provider' => 'outstand']);
    Http::fake();

    expect(connectReturn($this->get(returnUrl(OutstandService::seal($user->ulid, 'instagram'), ['error' => 'access_denied']))))
        ->toMatchArray(['connect' => 'failed', 'reason' => 'denied', 'network' => 'instagram']);
    Http::assertNothingSent();
});

it('an unexpected failure while settling is error, never a 500', function () {
    $user = User::factory()->create();
    Http::fake([
        // The finalize itself blows up (listed first: it would match the next pattern too).
        'api.outstand.test/v1/social-accounts/pending/sess1/finalize' => Http::response('', 500),
        'api.outstand.test/v1/social-accounts/pending/sess1' => Http::response(['success' => true, 'data' => [
            'network' => 'facebook', 'availablePages' => [['id' => 'pg1', 'type' => 'page', 'name' => 'Acme']],
        ]]),
    ]);

    expect(connectReturn($this->get(returnUrl(OutstandService::seal($user->ulid, 'facebook'), ['session' => 'sess1']))))
        ->toMatchArray(['connect' => 'failed', 'network' => 'facebook', 'reason' => 'error']);
});

describe('GET /social-accounts/connect-status', function () {
    it('records the attempt when the auth URL is minted, for every OAuth network', function (string $network) {
        Http::fake(['api.outstand.test/*' => Http::response(['success' => true, 'data' => ['auth_url' => 'https://www.outstand.so/o?state=s']])]);
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/v1/social-accounts/auth-url', ['platform' => $network])->assertOk();

        $this->actingAs($user)->getJson('/api/v1/social-accounts/connect-status')
            ->assertOk()
            ->assertJsonPath('data.network', $network)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.reason', null);
    })->with(OAUTH_NETWORKS);

    it('reads failed / expired once Outstand\'s 30-minute session is past and nothing came back', function () {
        Http::fake(['api.outstand.test/*' => Http::response(['success' => true, 'data' => ['auth_url' => 'https://www.outstand.so/o?state=s']])]);
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/api/v1/social-accounts/auth-url', ['platform' => 'instagram'])->assertOk();

        $this->travel(OutstandService::SESSION_MINUTES + 1)->minutes();

        $this->actingAs($user)->getJson('/api/v1/social-accounts/connect-status')
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.reason', 'expired')
            ->assertJsonPath('data.network', 'instagram');
    });

    it('is null before any connect', function () {
        $this->actingAs(User::factory()->create())->getJson('/api/v1/social-accounts/connect-status')->assertOk()->assertJsonPath('data', null);
    });

    it('needs a signed-in user', function () {
        $this->getJson('/api/v1/social-accounts/connect-status')->assertUnauthorized();
    });
});

it('Bluesky has no OAuth return: a refused sign-in answers the request itself', function () {
    expect(array_diff(array_column(SocialPlatform::cases(), 'value'), OAUTH_NETWORKS))->toBe([8 => 'bluesky']);
    Http::fake(['api.outstand.test/*' => Http::response(['success' => false, 'error' => 'Invalid app password'], 400)]);

    $this->actingAs(User::factory()->create())
        ->postJson('/api/v1/social-accounts/auth-url', ['platform' => 'bluesky', 'handle' => 'rani.bsky.social', 'app_password' => 'nope'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'provider_connect_refused');
});

it('maps provider error text to reasons', function (string $text, string $reason) {
    expect(\App\Http\Controllers\Api\V1\OutstandConnectController::reasonFor($text))->toBe($reason);
})->with([
    ['access_denied', 'denied'],
    ['user_denied', 'denied'],
    ['The user declined', 'denied'],
    ['Login cancelled', 'cancelled'],
    ['OAuth state expired', 'expired'],
    ['invalid_state', 'expired'],
    ['server_error', 'error'],
    ['unsuccessful', 'error'],
]);
