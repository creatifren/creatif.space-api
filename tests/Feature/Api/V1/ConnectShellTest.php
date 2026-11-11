<?php

use App\Models\User;
use App\Services\OutstandService;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/*
 * A connect started inside the Android app shell (`client: "android"` on
 * POST /social-accounts/auth-url) comes back to /social/connect with
 * `shell=android`, so the frontend can hand control back to the shell.
 * The web is unchanged: no client, no marker.
 */

beforeEach(function () {
    config([
        'services.social.provider' => 'outstand',
        'services.outstand.key' => 'sk_test',
        'services.outstand.base_url' => 'https://api.outstand.test/v1',
        'app.frontend_url' => 'https://app.test',
    ]);
});

/** The state Outstand was handed in redirect_uri, from the faked auth-url call. */
function shellAuthUrlState(TestCase $test, User $user, array $body, array $fakes = []): string
{
    Http::fake($fakes + ['api.outstand.test/*' => Http::response(['success' => true, 'data' => ['auth_url' => 'https://www.outstand.so/x']])]);
    $test->actingAs($user)->postJson('/api/v1/social-accounts/auth-url', $body)->assertOk();

    $state = null;
    Http::assertSent(function (HttpRequest $r) use (&$state) {
        parse_str((string) parse_url((string) ($r['redirect_uri'] ?? ''), PHP_URL_QUERY), $q);
        $state = $q['state'] ?? $state;

        return true;
    });

    return (string) $state;
}

function shellReturn(string $state, array $query = []): string
{
    return '/api/v1/webhooks/outstand?'.http_build_query(['state' => $state] + $query);
}

/** @return array<string, string> the redirect's query */
function shellQuery($response): array
{
    $location = (string) $response->assertRedirect()->headers->get('Location');
    expect($location)->toStartWith('https://app.test/social/connect?');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $q);

    return $q;
}

it('seals client=android into the state, and only android is accepted', function () {
    $user = User::factory()->create();

    expect(OutstandService::sealed(shellAuthUrlState($this, $user, ['platform' => 'instagram', 'client' => 'android'])))
        ->toMatchArray(['tenant' => $user->ulid, 'network' => 'instagram', 'client' => 'android'])
        ->and(OutstandService::sealed(shellAuthUrlState($this, $user, ['platform' => 'instagram'])))
        ->toMatchArray(['client' => null]);

    $this->actingAs($user)->postJson('/api/v1/social-accounts/auth-url', ['platform' => 'instagram', 'client' => 'ios'])
        ->assertStatus(422)->assertJsonValidationErrors('client');
});

describe('started in the Android shell', function () {
    it('marks a success with shell=android', function () {
        $user = User::factory()->create();
        $state = shellAuthUrlState($this, $user, ['platform' => 'instagram', 'client' => 'android'], [
            'api.outstand.test/v1/social-accounts*' => Http::response(['success' => true, 'data' => [[
                'id' => 'acc_ig', 'network' => 'instagram', 'username' => 'studio', 'tenant_id' => $user->ulid, 'isActive' => 1,
            ]], 'count' => 1, 'total' => 1]),
        ]);

        expect(shellQuery($this->get(shellReturn($state))))
            ->toMatchArray(['connect' => 'success', 'network' => 'instagram', 'shell' => 'android']);
    });

    it('marks a failure with shell=android, and the refresh replay keeps it', function () {
        $user = User::factory()->create();
        $state = shellAuthUrlState($this, $user, ['platform' => 'tiktok', 'client' => 'android']);
        Http::fake();

        $first = $this->get(shellReturn($state, ['error' => 'access_denied']));
        expect(shellQuery($first))->toMatchArray(['connect' => 'failed', 'reason' => 'denied', 'shell' => 'android']);

        $again = $this->get(shellReturn($state));
        expect($again->headers->get('Location'))->toBe($first->headers->get('Location'));
        Http::assertNothingSent();
    });

    it('marks the failure for a tenant that no longer exists', function () {
        $state = OutstandService::seal('01NOSUCHUSER0000000000000', 'x', 'android');

        expect(shellQuery($this->get(shellReturn($state))))
            ->toMatchArray(['connect' => 'failed', 'reason' => 'invalid_state', 'shell' => 'android']);
    });

    it('marks a Bluesky connect, which has no round trip', function () {
        Http::fake(['api.outstand.test/*' => Http::response(['success' => true, 'data' => ['id' => 'acc_bs']])]);
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/v1/social-accounts/auth-url', [
            'platform' => 'bluesky', 'handle' => 'studio.bsky.social', 'app_password' => 'abcd-efgh-ijkl-mnop', 'client' => 'android',
        ])->assertOk()->assertJsonPath('data.url', 'https://app.test/social/connect?social=connected&shell=android');
    });
});

describe('started on the web', function () {
    it('never carries the marker, success or failure', function () {
        $user = User::factory()->create();
        $state = shellAuthUrlState($this, $user, ['platform' => 'instagram']);
        Http::fake();

        $q = shellQuery($this->get(shellReturn($state, ['error' => 'access_denied'])));
        expect($q)->toMatchArray(['connect' => 'failed', 'reason' => 'denied'])->not->toHaveKey('shell');
    });

    it('a state it cannot read names no shell', function () {
        expect(shellQuery($this->get(shellReturn(str_repeat('a', 40)))))->not->toHaveKey('shell');
    });

    it('Bluesky stays as it was', function () {
        Http::fake(['api.outstand.test/*' => Http::response(['success' => true, 'data' => ['id' => 'acc_bs']])]);

        $this->actingAs(User::factory()->create())->postJson('/api/v1/social-accounts/auth-url', [
            'platform' => 'bluesky', 'handle' => 'studio.bsky.social', 'app_password' => 'abcd-efgh-ijkl-mnop',
        ])->assertOk()->assertJsonPath('data.url', 'https://app.test/social/connect?social=connected');
    });
});
