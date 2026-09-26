<?php

use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'services.outstand.key' => 'test-key',
        'services.outstand.org_id' => 'org_1',
        'services.outstand.base_url' => 'https://api.outstand.test/v1',
        'app.frontend_url' => 'https://app.test',
    ]);
});

/** Ask for a connect URL as $user and return the nonce in its return path. */
function connectNonce($test, User $user, string $platform = 'instagram'): string
{
    $url = $test->actingAs($user)
        ->postJson('/api/v1/social-accounts/auth-url', ['platform' => $platform])
        ->assertOk()
        ->json('data.url');

    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    return basename((string) parse_url($query['redirect_uri'], PHP_URL_PATH));
}

function fakeOrgAccounts(array $accounts): void
{
    Http::fake(['api.outstand.test/*' => Http::response(['data' => $accounts])]);
}

describe('social accounts', function () {
    it('rejects guests', function () {
        $this->getJson('/api/v1/social-accounts')->assertUnauthorized();
    });

    it('lists only own accounts', function () {
        $user = User::factory()->create();
        $account = SocialAccount::factory()->for($user)->create();
        SocialAccount::factory()->create(); // someone else's

        $this->actingAs($user)
            ->getJson('/api/v1/social-accounts')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $account->ulid);
    });

    it('builds the Outstand connect url with our callback as redirect_uri', function () {
        Http::fake();

        $url = $this->actingAs(User::factory()->create())
            ->postJson('/api/v1/social-accounts/auth-url', ['platform' => 'instagram'])
            ->assertOk()
            ->json('data.url');

        expect($url)->toStartWith('https://www.outstand.so/app/api/socials/instagram/org_1?redirect_uri=')
            ->toContain(urlencode('/api/v1/social-accounts/callback/'));

        Http::assertNothingSent();
    });

    it('rejects unknown platforms and the ones not wired yet', function (string $platform) {
        $this->actingAs(User::factory()->create())
            ->postJson('/api/v1/social-accounts/auth-url', ['platform' => $platform])
            ->assertUnprocessable();
    })->with(['myspace', 'x', 'bluesky']);

    it('throws when the org id is not configured', function () {
        config(['services.outstand.org_id' => '']);

        $this->withoutExceptionHandling()
            ->actingAs(User::factory()->create())
            ->postJson('/api/v1/social-accounts/auth-url', ['platform' => 'instagram']);
    })->throws(RuntimeException::class);
});

describe('the connect callback', function () {
    it('records the granted account for the user who asked, once', function () {
        $user = User::factory()->create();
        $nonce = connectNonce($this, $user);
        fakeOrgAccounts([['id' => 'acc_new', 'network' => 'instagram', 'username' => 'rani', 'isActive' => true]]);

        $this->get("/api/v1/social-accounts/callback/{$nonce}?success=true&account_id=acc_new&username=rani")
            ->assertRedirect('https://app.test/social/connect?social=connected');

        expect($user->socialAccounts()->where('provider_account_id', 'acc_new')->first())
            ->not->toBeNull()
            ->username->toBe('rani');

        // Replaying the same return URL finds nothing.
        $this->get("/api/v1/social-accounts/callback/{$nonce}?success=true&account_id=acc_new")
            ->assertRedirect('https://app.test/social/connect?social=error');
    });

    it('refuses an unknown nonce and a provider error', function () {
        $this->get('/api/v1/social-accounts/callback/nope?account_id=acc_x')
            ->assertRedirect('https://app.test/social/connect?social=error');

        $nonce = connectNonce($this, User::factory()->create());

        $this->get("/api/v1/social-accounts/callback/{$nonce}?error=access_denied")
            ->assertRedirect('https://app.test/social/connect?social=error');

        expect(SocialAccount::query()->count())->toBe(0);
    });

    it('refuses an account the org does not hold, or on another network', function () {
        $user = User::factory()->create();
        fakeOrgAccounts([['id' => 'acc_fb', 'network' => 'facebook', 'isActive' => true]]);

        $nonce = connectNonce($this, $user);
        $this->get("/api/v1/social-accounts/callback/{$nonce}?account_id=acc_missing")
            ->assertRedirect('https://app.test/social/connect?social=error');

        $nonce = connectNonce($this, $user);
        $this->get("/api/v1/social-accounts/callback/{$nonce}?account_id=acc_fb")
            ->assertRedirect('https://app.test/social/connect?social=error');

        expect(SocialAccount::query()->count())->toBe(0);
    });

    it('refuses an account already connected by another user', function () {
        SocialAccount::factory()->create(['provider_account_id' => 'acc_taken']);
        $user = User::factory()->create();
        $nonce = connectNonce($this, $user);
        fakeOrgAccounts([['id' => 'acc_taken', 'network' => 'instagram', 'isActive' => true]]);

        $this->get("/api/v1/social-accounts/callback/{$nonce}?account_id=acc_taken")
            ->assertRedirect('https://app.test/social/connect?social=error');

        expect($user->socialAccounts()->count())->toBe(0);
    });
});

describe('sync and disconnect', function () {
    it("marks own rows the org lost as disconnected, never touches or creates others'", function () {
        $user = User::factory()->create();
        $kept = SocialAccount::factory()->for($user)->create(['provider_account_id' => 'acc_live']);
        $gone = SocialAccount::factory()->for($user)->create(['provider_account_id' => 'acc_gone']);
        $inactive = SocialAccount::factory()->for($user)->create(['provider_account_id' => 'acc_off']);
        $stranger = SocialAccount::factory()->create(['provider_account_id' => 'acc_stranger']);

        fakeOrgAccounts([
            ['id' => 'acc_live', 'network' => 'instagram', 'isActive' => true],
            ['id' => 'acc_off', 'network' => 'instagram', 'isActive' => false],
            ['id' => 'acc_other', 'network' => 'facebook', 'isActive' => true],
        ]);

        $this->actingAs($user)
            ->postJson('/api/v1/social-accounts/sync')
            ->assertOk()
            ->assertJsonCount(3, 'data');

        expect($kept->fresh()->status->value)->toBe('connected')
            ->and($gone->fresh()->status->value)->toBe('disconnected')
            ->and($inactive->fresh()->status->value)->toBe('disconnected')
            ->and($stranger->fresh()->status->value)->toBe('connected')
            ->and(SocialAccount::query()->where('provider_account_id', 'acc_other')->exists())->toBeFalse();
    });

    it('disconnects locally, keeps the row', function () {
        Http::fake();
        $user = User::factory()->create();
        $account = SocialAccount::factory()->for($user)->create();

        $this->actingAs($user)
            ->deleteJson("/api/v1/social-accounts/{$account->ulid}")
            ->assertNoContent();

        expect($account->fresh()->status->value)->toBe('disconnected');
        Http::assertNothingSent();
    });

    it("forbids touching another user's account", function () {
        $account = SocialAccount::factory()->create();

        $this->actingAs(User::factory()->create())
            ->deleteJson("/api/v1/social-accounts/{$account->ulid}")
            ->assertForbidden();
    });
});
