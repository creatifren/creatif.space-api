<?php

use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'services.postforme.key' => 'test-key',
        'services.postforme.base_url' => 'https://api.postforme.test/v1',
    ]);
});

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

    it('returns an auth url tagged with the user ulid', function () {
        Http::fake([
            'api.postforme.test/*' => Http::response(['url' => 'https://auth.postforme.test/connect?x=1']),
        ]);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/v1/social-accounts/auth-url', ['platform' => 'instagram'])
            ->assertOk()
            ->assertJsonPath('data.url', 'https://auth.postforme.test/connect?x=1');

        Http::assertSent(fn ($request) => $request['platform'] === 'instagram'
            && $request['external_id'] === $user->ulid);
    });

    it('rejects an unknown platform', function () {
        $this->actingAs(User::factory()->create())
            ->postJson('/api/v1/social-accounts/auth-url', ['platform' => 'myspace'])
            ->assertUnprocessable();

        Http::fake();
        Http::assertNothingSent();
    });

    it('throws when the API key is not configured', function () {
        config(['services.postforme.key' => '']);

        $this->withoutExceptionHandling()
            ->actingAs(User::factory()->create())
            ->postJson('/api/v1/social-accounts/auth-url', ['platform' => 'x']);
    })->throws(RuntimeException::class);

    it('syncs: upserts from the provider and marks missing rows disconnected', function () {
        $user = User::factory()->create();
        $stale = SocialAccount::factory()->for($user)->create([
            'platform' => 'facebook',
            'provider_account_id' => 'acc_gone',
        ]);

        Http::fake([
            'api.postforme.test/*' => Http::response(['data' => [
                [
                    'id' => 'acc_new',
                    'platform' => 'instagram',
                    'username' => '@rani',
                    'status' => 'connected',
                ],
            ]]),
        ]);

        $this->actingAs($user)
            ->postJson('/api/v1/social-accounts/sync')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        expect($user->socialAccounts()->where('provider_account_id', 'acc_new')->first())
            ->not->toBeNull()
            ->username->toBe('@rani')
            ->and($stale->fresh()->status->value)->toBe('disconnected');
    });

    it('disconnects: tells the provider, keeps the row as disconnected', function () {
        Http::fake(['api.postforme.test/*' => Http::response(['message' => 'ok'])]);
        $user = User::factory()->create();
        $account = SocialAccount::factory()->for($user)->create();

        $this->actingAs($user)
            ->deleteJson("/api/v1/social-accounts/{$account->ulid}")
            ->assertNoContent();

        expect($account->fresh()->status->value)->toBe('disconnected')
            ->and(SocialAccount::query()->count())->toBe(1);

        Http::assertSentCount(2); // disconnect + delete
    });

    it("forbids touching another user's account", function () {
        $account = SocialAccount::factory()->create();

        $this->actingAs(User::factory()->create())
            ->deleteJson("/api/v1/social-accounts/{$account->ulid}")
            ->assertForbidden();
    });
});
