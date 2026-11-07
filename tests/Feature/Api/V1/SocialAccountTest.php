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
            // Or a second Instagram reuses the signed-in one.
            && $request['force_reauth'] === true
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
        $only->targets()->create(['social_account_id' => $gone->id, 'status' => 'pending', 'paid_with' => 'credits', 'charged_at' => now()]);
        $shared = SocialPost::factory()->for($user)->create(['provider_post_id' => 'post_shared']);
        $shared->targets()->create(['social_account_id' => $gone->id, 'status' => 'pending', 'paid_with' => 'credits', 'charged_at' => now()]);
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

    it('claims: parks the account, moves it only after a fresh sign-in', function () {
        $owner = User::factory()->create();
        $claimant = User::factory()->create();
        $old = SocialAccount::factory()->for($owner)->create([
            'platform' => 'instagram',
            'provider_account_id' => 'spc_ig',
        ]);

        $remote = ['id' => 'spc_ig', 'platform' => 'instagram', 'username' => '@shop',
            'status' => 'connected', 'external_id' => $owner->ulid, 'access_token' => 'tok_old'];
        $fake = function ($request) use (&$remote) {
            $url = $request->url();
            if ($request->method() === 'PATCH') {
                $remote['external_id'] = $request['external_id'];

                return Http::response($remote);
            }
            if (str_contains($url, '/auth-url')) {
                return Http::response(['url' => 'https://auth.postforme.test/claim']);
            }
            if (str_contains($url, '/social-accounts/spc_ig')) {
                return Http::response($remote);
            }
            parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

            return Http::response(['data' => ($q['external_id'] ?? '') === $remote['external_id'] ? [$remote] : []]);
        };
        Http::fake(['api.postforme.test/*' => $fake]);

        $this->actingAs($claimant)
            ->postJson('/api/v1/social-accounts/claim', ['account_id' => 'spc_ig'])
            ->assertOk()
            ->assertJsonPath('data.url', 'https://auth.postforme.test/claim');
        $tag = $remote['external_id'];
        expect($tag)->toStartWith('claim_');

        // The owner syncing mid-claim keeps the row.
        $this->actingAs($owner)->postJson('/api/v1/social-accounts/sync')->assertOk();
        expect($old->fresh()->status->value)->toBe('connected');

        // Back without a fresh sign-in: the account goes home.
        $this->actingAs($claimant)->postJson('/api/v1/social-accounts/sync')->assertJsonCount(0, 'data');
        expect($remote['external_id'])->toBe($owner->ulid);

        // Again, this time the sign-in renewed the token.
        $this->actingAs($claimant)->postJson('/api/v1/social-accounts/claim', ['account_id' => 'spc_ig'])->assertOk();
        $remote['access_token'] = 'tok_new';
        $this->actingAs($claimant)->postJson('/api/v1/social-accounts/sync')->assertJsonCount(1, 'data');

        expect($remote['external_id'])->toBe($claimant->ulid)
            ->and($old->fresh()->status->value)->toBe('disconnected')
            ->and($claimant->socialAccounts()->where('provider_account_id', 'spc_ig')->exists())->toBeTrue();
    });

    it('refuses to claim an account one already owns', function () {
        $user = User::factory()->create();
        Http::fake(['api.postforme.test/*' => Http::response([
            'id' => 'spc_mine', 'platform' => 'instagram', 'external_id' => $user->ulid,
        ])]);

        $this->actingAs($user)
            ->postJson('/api/v1/social-accounts/claim', ['account_id' => 'spc_mine'])
            ->assertUnprocessable();

        Http::assertNotSent(fn ($r) => $r->method() === 'PATCH');
    });

    it("forbids touching another user's account", function () {
        $account = SocialAccount::factory()->create();

        $this->actingAs(User::factory()->create())
            ->deleteJson("/api/v1/social-accounts/{$account->ulid}")
            ->assertForbidden();
    });
});
