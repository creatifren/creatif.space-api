<?php

use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'services.outstand.key' => 'test-key',
        'services.outstand.base_url' => 'https://api.outstand.test/v1',
    ]);
});

/** Outstand's answer to POST /posts: echoes back every account it accepted. */
function outstandPost(string $id, array $accounts, string $status = 'pending'): array
{
    return ['id' => $id, 'socialAccounts' => array_map(
        fn ($a) => ['id' => $a, 'status' => $status],
        $accounts,
    )];
}

/** Social posting is a paid feature — creators in these tests are Premium. */
function premiumUser(): User
{
    $user = User::factory()->create();
    Subscription::factory()->for($user)->create();

    return $user;
}

describe('creating posts', function () {
    it('sends one provider post per distinct caption, ids on the targets', function () {
        $user = premiumUser();
        $ig = SocialAccount::factory()->for($user)->create(['platform' => 'instagram']);
        $fb = SocialAccount::factory()->for($user)->create(['platform' => 'facebook']);
        $li = SocialAccount::factory()->for($user)->create(['platform' => 'linkedin']);

        Http::fake([
            'api.outstand.test/*' => Http::sequence()
                ->push(outstandPost('post_main', [$ig->provider_account_id, $fb->provider_account_id]))
                ->push(outstandPost('post_li', [$li->provider_account_id])),
        ]);

        $this->actingAs($user)
            ->postJson('/api/v1/social-posts', [
                'caption' => 'Winter Noel — the full set.',
                'account_ids' => [$ig->ulid, $fb->ulid, $li->ulid],
                'captions' => [$li->ulid => 'Longer one for LinkedIn.'],
                'media' => [['url' => 'https://assets.creatif.space/winter-noel-03.jpg']],
                'scheduled_at' => now()->addDay()->toIso8601String(),
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'scheduled')
            ->assertJsonCount(3, 'data.targets');

        $post = SocialPost::query()->first();
        expect($post->targets()->where('social_account_id', $ig->id)->value('provider_post_id'))->toBe('post_main')
            ->and($post->targets()->where('social_account_id', $li->id)->first())
            ->provider_post_id->toBe('post_li')
            ->configuration->toBe(['caption' => 'Longer one for LinkedIn.']);

        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/posts')
            && $request['containers'][0]['content'] === 'Longer one for LinkedIn.'
            && $request['accounts'] === [$li->provider_account_id]
            && $request['containers'][0]['media'][0]['filename'] === 'winter-noel-03.jpg'
            && isset($request['scheduledAt']));
    });

    it('takes back the groups already sent when a later one fails, and records nothing', function () {
        $user = premiumUser();
        $ig = SocialAccount::factory()->for($user)->create();
        $li = SocialAccount::factory()->for($user)->create(['platform' => 'linkedin']);

        Http::fake(fn ($request) => match (true) {
            $request->method() === 'DELETE' => Http::response(null, 204),
            $request['accounts'] === [$ig->provider_account_id] => Http::response(outstandPost('post_first', $request['accounts'])),
            default => Http::response(['message' => 'boom'], 500),
        });

        $this->actingAs($user)
            ->postJson('/api/v1/social-posts', [
                'caption' => 'Main.',
                'account_ids' => [$ig->ulid, $li->ulid],
                'captions' => [$li->ulid => 'Other.'],
            ])
            ->assertServerError();

        expect(SocialPost::query()->count())->toBe(0);
        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && str_ends_with($request->url(), '/posts/post_first'));
    });

    it('refuses when Outstand silently drops an account', function () {
        $user = premiumUser();
        $a = SocialAccount::factory()->for($user)->create();
        $b = SocialAccount::factory()->for($user)->create(['platform' => 'facebook']);

        Http::fake([
            'api.outstand.test/*' => Http::response(outstandPost('post_short', [$a->provider_account_id])),
        ]);

        $this->actingAs($user)
            ->postJson('/api/v1/social-posts', [
                'caption' => 'Both.',
                'account_ids' => [$a->ulid, $b->ulid],
            ])
            ->assertServerError();

        expect(SocialPost::query()->count())->toBe(0);
        Http::assertSent(fn ($request) => $request->method() === 'DELETE');
    });

    it('marks an immediate post published when every account already went out', function () {
        Http::fake(fn ($request) => Http::response(outstandPost('post_now', $request['accounts'], 'published')));
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

        // Outstand's horizon is 30 days.
        $this->actingAs($user)
            ->postJson('/api/v1/social-posts', $base + [
                'caption' => 'ok',
                'scheduled_at' => now()->addDays(31)->toIso8601String(),
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
        Http::fake(fn ($request) => Http::response(outstandPost('post_61', $request['accounts'])));
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
        Http::fake(fn ($request) => Http::response(outstandPost('post_new', $request['accounts'])));
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

    it('cancels every provider post behind a scheduled post, once each', function () {
        Http::fake(['api.outstand.test/*' => Http::response(null, 204)]);
        $user = User::factory()->create();
        $post = SocialPost::factory()->for($user)->create();
        foreach (['post_a', 'post_a', 'post_b'] as $providerId) {
            $post->targets()->create([
                'social_account_id' => SocialAccount::factory()->for($user)->create()->id,
                'provider_post_id' => $providerId,
            ]);
        }

        $this->actingAs($user)
            ->deleteJson("/api/v1/social-posts/{$post->ulid}")
            ->assertNoContent();

        expect($post->fresh()->status->value)->toBe('cancelled');
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && str_ends_with($request->url(), '/posts/post_b'));
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
