<?php

use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use App\Support\Credits;
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
            && $request['external_id'] === $user->ulid
            // Login with Instagram, not via a Facebook Page.
            && $request['platform_data']['instagram']['connection_type'] === 'instagram');
    });

    it('sends bluesky its handle and app password, and requires both', function () {
        Http::fake([
            'api.postforme.test/*' => Http::response(['url' => 'https://auth.postforme.test/done']),
        ]);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/v1/social-accounts/auth-url', ['platform' => 'bluesky'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['handle', 'app_password']);

        $this->actingAs($user)
            ->postJson('/api/v1/social-accounts/auth-url', [
                'platform' => 'bluesky',
                'handle' => '@rani.bsky.social',
                'app_password' => 'abcd-efgh-ijkl-mnop',
            ])
            ->assertOk();

        Http::assertSent(fn ($request) => $request['platform_data']['bluesky'] === [
            'handle' => 'rani.bsky.social',
            'app_password' => 'abcd-efgh-ijkl-mnop',
        ]);
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

    it('disconnects: a post aimed only there becomes a draft, a shared one loses the destination', function () {
        Http::fake(['api.postforme.test/*' => Http::response(['message' => 'ok'])]);
        $user = User::factory()->create();
        Credits::grant($user, 10_000, 'Test');
        $gone = SocialAccount::factory()->for($user)->create();
        $kept = SocialAccount::factory()->for($user)->create();

        $only = SocialPost::factory()->for($user)->create(['provider_post_id' => 'post_only']);
        $only->targets()->create(['social_account_id' => $gone->id, 'status' => 'pending']);
        $shared = SocialPost::factory()->for($user)->create(['provider_post_id' => 'post_shared']);
        $shared->targets()->create(['social_account_id' => $gone->id, 'status' => 'pending']);
        $shared->targets()->create(['social_account_id' => $kept->id, 'status' => 'pending']);
        $before = Credits::balance($user);

        $this->actingAs($user)->deleteJson("/api/v1/social-accounts/{$gone->ulid}")->assertNoContent();

        expect($only->fresh())->status->value->toBe('draft')->provider_post_id->toBeNull()
            ->and($only->targets()->count())->toBe(0)
            ->and($shared->targets()->pluck('social_account_id')->all())->toBe([$kept->id])
            ->and(Credits::balance($user))->toBe($before + 2 * Credits::PER_SEND);

        Http::assertSent(fn ($r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/social-posts/post_only'));
        Http::assertSent(fn ($r) => $r->method() === 'PUT' && str_ends_with($r->url(), '/social-posts/post_shared')
            && $r['social_accounts'] === [$kept->provider_account_id]);
    });

    it("forbids touching another user's account", function () {
        $account = SocialAccount::factory()->create();

        $this->actingAs(User::factory()->create())
            ->deleteJson("/api/v1/social-accounts/{$account->ulid}")
            ->assertForbidden();
    });
});
