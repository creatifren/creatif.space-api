<?php

use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'services.postforme.key' => 'test-key',
        'services.postforme.base_url' => 'https://api.postforme.test/v1',
    ]);
});

/** Social posting is a paid feature — creators in these tests are Premium. */
function premiumUser(): User
{
    $user = User::factory()->create();
    Subscription::factory()->for($user)->create();

    return $user;
}

describe('creating posts', function () {
    it('creates a scheduled post with pending targets and per-account captions', function () {
        Http::fake([
            'api.postforme.test/*' => Http::response(['id' => 'post_abc', 'status' => 'scheduled']),
        ]);
        $user = premiumUser();
        $ig = SocialAccount::factory()->for($user)->create(['platform' => 'instagram']);
        $x = SocialAccount::factory()->for($user)->create(['platform' => 'x']);

        $this->actingAs($user)
            ->postJson('/api/v1/social-posts', [
                'caption' => 'Winter Noel — the full set.',
                'account_ids' => [$ig->ulid, $x->ulid],
                'captions' => [$x->ulid => 'Short one for X.'],
                'media' => [['url' => 'https://assets.creatif.space/winter-noel-03.jpg']],
                'scheduled_at' => now()->addDay()->toIso8601String(),
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'scheduled')
            ->assertJsonCount(2, 'data.targets');

        $post = SocialPost::query()->first();
        expect($post->provider_post_id)->toBe('post_abc')
            ->and($post->targets()->count())->toBe(2)
            ->and($post->targets()->where('social_account_id', $x->id)->first()->configuration)
            ->toBe(['caption' => 'Short one for X.']);

        Http::assertSent(function ($request) use ($x) {
            $body = $request->data();

            return str_ends_with($request->url(), '/social-posts')
                && count($body['social_accounts']) === 2
                && $body['account_configurations'][0]['social_account_id'] === $x->provider_account_id;
        });
    });

    it('marks an immediate post published when the provider says processed', function () {
        Http::fake([
            'api.postforme.test/*' => Http::response(['id' => 'post_now', 'status' => 'processed']),
        ]);
        $user = premiumUser();
        $account = SocialAccount::factory()->for($user)->create();

        $this->actingAs($user)
            ->postJson('/api/v1/social-posts', [
                'caption' => 'Now.',
                'account_ids' => [$account->ulid],
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'published');
    });

    it("refuses another user's account and sends nothing", function () {
        Http::fake();
        $foreign = SocialAccount::factory()->create();

        $this->actingAs(premiumUser())
            ->postJson('/api/v1/social-posts', [
                'caption' => 'Sneaky.',
                'account_ids' => [$foreign->ulid],
            ])
            ->assertUnprocessable();

        Http::assertNothingSent();
    });

    it('refuses a disconnected account of your own', function () {
        Http::fake();
        $user = premiumUser();
        $account = SocialAccount::factory()->for($user)->disconnected()->create();

        $this->actingAs($user)
            ->postJson('/api/v1/social-posts', [
                'caption' => 'Nope.',
                'account_ids' => [$account->ulid],
            ])
            ->assertUnprocessable();

        Http::assertNothingSent();
    });

    it('validates caption length, media scheme, and past schedule', function () {
        $user = premiumUser();
        $account = SocialAccount::factory()->for($user)->create();

        $base = ['account_ids' => [$account->ulid]];

        $this->actingAs($user)
            ->postJson('/api/v1/social-posts', $base + ['caption' => str_repeat('a', 63207)])
            ->assertUnprocessable();

        $this->actingAs($user)
            ->postJson('/api/v1/social-posts', $base + [
                'caption' => 'ok',
                'media' => [['url' => 'http://insecure.example/x.jpg']],
            ])
            ->assertUnprocessable();

        $this->actingAs($user)
            ->postJson('/api/v1/social-posts', $base + [
                'caption' => 'ok',
                'scheduled_at' => now()->subHour()->toIso8601String(),
            ])
            ->assertUnprocessable();
    });
});

describe('plan quota', function () {
    it('refuses a Free user with an upgrade message and sends nothing', function () {
        Http::fake();
        $user = User::factory()->create(); // no subscription = Free, 0 posts
        $account = SocialAccount::factory()->for($user)->create();

        $this->actingAs($user)
            ->postJson('/api/v1/social-posts', [
                'caption' => 'Free tier.',
                'account_ids' => [$account->ulid],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('caption');

        Http::assertNothingSent();
    });

    it('caps Premium at 60 posts a month, cancelled ones not counted', function () {
        Http::fake([
            'api.postforme.test/*' => Http::response(['id' => 'post_61', 'status' => 'scheduled']),
        ]);
        $user = premiumUser();
        $account = SocialAccount::factory()->for($user)->create();

        SocialPost::factory()->for($user)->count(60)->create();

        $payload = ['caption' => 'One too many.', 'account_ids' => [$account->ulid]];

        $this->actingAs($user)
            ->postJson('/api/v1/social-posts', $payload)
            ->assertUnprocessable();

        // Cancelling one frees the slot.
        $user->socialPosts()->first()->update(['status' => 'cancelled']);

        $this->actingAs($user)
            ->postJson('/api/v1/social-posts', $payload)
            ->assertCreated();
    });

    it("ignores last month's posts", function () {
        Http::fake([
            'api.postforme.test/*' => Http::response(['id' => 'post_new', 'status' => 'scheduled']),
        ]);
        $user = premiumUser();
        $account = SocialAccount::factory()->for($user)->create();

        SocialPost::factory()->for($user)->count(60)->create([
            'created_at' => now()->subMonth(),
        ]);

        $this->actingAs($user)
            ->postJson('/api/v1/social-posts', [
                'caption' => 'Fresh month.',
                'account_ids' => [$account->ulid],
            ])
            ->assertCreated();
    });
});

describe('listing and cancelling', function () {
    it('filters by status and hides other users', function () {
        $user = User::factory()->create();
        SocialPost::factory()->for($user)->create();
        SocialPost::factory()->for($user)->published()->create();
        SocialPost::factory()->create(); // someone else's

        $this->actingAs($user)
            ->getJson('/api/v1/social-posts?status=scheduled')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'scheduled');

        $this->actingAs($user)
            ->getJson('/api/v1/social-posts')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    });

    it('cancels a scheduled post via the provider', function () {
        Http::fake(['api.postforme.test/*' => Http::response(['message' => 'ok'])]);
        $user = User::factory()->create();
        $post = SocialPost::factory()->for($user)->create();

        $this->actingAs($user)
            ->deleteJson("/api/v1/social-posts/{$post->ulid}")
            ->assertNoContent();

        expect($post->fresh()->status->value)->toBe('cancelled');
        Http::assertSent(fn ($request) => $request->method() === 'DELETE');
    });

    it('refuses to cancel a published post', function () {
        Http::fake();
        $user = User::factory()->create();
        $post = SocialPost::factory()->for($user)->published()->create();

        $this->actingAs($user)
            ->deleteJson("/api/v1/social-posts/{$post->ulid}")
            ->assertUnprocessable();

        Http::assertNothingSent();
    });

    it("forbids cancelling another user's post", function () {
        $post = SocialPost::factory()->create();

        $this->actingAs(User::factory()->create())
            ->deleteJson("/api/v1/social-posts/{$post->ulid}")
            ->assertForbidden();
    });

    it('rejects guests', function () {
        $this->getJson('/api/v1/social-posts')->assertUnauthorized();
    });
});
