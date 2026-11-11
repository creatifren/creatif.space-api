<?php

use App\Http\Middleware\CapTokenSends;
use App\Models\Plan;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Credits;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/*
 * The V1 pieces behind the MCP server's M2 tools: the send preview, the
 * daily send cap for bearer-token callers, and approval links from drafts.
 */

beforeEach(function () {
    foreach (Plan::all() as $plan) {
        $plan->update(['quotas' => [...$plan->quotas, 'social_posts_monthly' => 0, 'credits_monthly' => 0]]);
    }
    config([
        'services.postforme.key' => 'test-key',
        'services.postforme.base_url' => 'https://api.postforme.test/v1',
    ]);
});

function draftFor(User $user, array $platforms = ['instagram']): SocialPost
{
    $post = SocialPost::factory()->for($user)->create([
        'status' => 'draft',
        'provider_post_id' => null,
        'scheduled_at' => null,
        'caption' => 'Draft caption',
    ]);
    foreach ($platforms as $platform) {
        $account = SocialAccount::factory()->for($user)->create(['platform' => $platform]);
        $post->targets()->create(['social_account_id' => $account->id, 'status' => 'pending']);
    }

    return $post;
}

/** The confirm_token the send preview hands out for this post. */
function previewToken($test, User $user, SocialPost $post): string
{
    return $test->actingAs($user)->getJson("/api/v1/social-posts/{$post->ulid}/send-preview")->json('data.confirm_token');
}

describe('GET /social-posts/{post}/send-preview', function () {
    it('prices every destination and says whether the wallet covers it', function () {
        $user = User::factory()->create();
        Credits::grant($user, 30_000, 'Test');
        $post = draftFor($user, ['instagram', 'x']);

        $response = $this->actingAs($user)
            ->getJson("/api/v1/social-posts/{$post->ulid}/send-preview")
            ->assertOk()
            ->assertJsonPath('data.post_id', $post->ulid)
            ->assertJsonPath('data.sendable', true)
            ->assertJsonCount(2, 'data.targets')
            ->assertJsonPath('data.credits.can_afford', true)
            ->assertJsonPath('data.daily_cap.cap', CapTokenSends::DAILY_CAP);

        $targets = collect($response->json('data.targets'));
        expect($response->json('data.credits.price'))->toBe($targets->sum('credits'))
            ->and($targets->pluck('platform')->sort()->values()->all())->toBe(['instagram', 'x'])
            ->and($targets->first())->not->toHaveKey('destination');
        Http::assertNothingSent();
    });

    it('says when the balance is short, and that a sent post is not sendable', function () {
        $user = User::factory()->create(); // no Credits
        $post = draftFor($user);
        $this->actingAs($user)
            ->getJson("/api/v1/social-posts/{$post->ulid}/send-preview")
            ->assertOk()
            ->assertJsonPath('data.credits.can_afford', false);

        $sent = SocialPost::factory()->for($user)->create();
        $this->actingAs($user)
            ->getJson("/api/v1/social-posts/{$sent->ulid}/send-preview")
            ->assertOk()
            ->assertJsonPath('data.sendable', false);
    });

    it('refuses someone else\'s post', function () {
        $post = draftFor(User::factory()->create());
        $this->actingAs(User::factory()->create())
            ->getJson("/api/v1/social-posts/{$post->ulid}/send-preview")
            ->assertForbidden();
    });
});

describe('daily send cap for bearer-token callers', function () {
    it('allows the cap, then answers 429 daily_send_cap', function () {
        Http::fake(['api.postforme.test/*' => Http::response(['id' => 'post_x', 'status' => 'scheduled'])]);
        $user = User::factory()->create();
        Credits::grant($user, 1_000_000, 'Test');
        Cache::put('token-sends:'.$user->id.':'.now('UTC')->toDateString(), CapTokenSends::DAILY_CAP - 1, 3600);

        $first = draftFor($user);
        $this->actingAs($user)->withToken('cs_live_test')
            ->postJson("/api/v1/social-posts/{$first->ulid}/send", ['confirm_token' => previewToken($this, $user, $first)])
            ->assertOk();

        $second = draftFor($user);
        $this->actingAs($user)->withToken('cs_live_test')
            ->postJson("/api/v1/social-posts/{$second->ulid}/send", ['confirm_token' => previewToken($this, $user, $second)])
            ->assertStatus(429)
            ->assertJsonPath('code', 'daily_send_cap');

        expect($second->fresh()->status->value)->toBe('draft')
            ->and(CapTokenSends::status($user)['remaining'])->toBe(0);
    });

    it('never caps the app (cookie session, no bearer)', function () {
        Http::fake(['api.postforme.test/*' => Http::response(['id' => 'post_x', 'status' => 'scheduled'])]);
        $user = User::factory()->create();
        Credits::grant($user, 1_000_000, 'Test');
        Cache::put('token-sends:'.$user->id.':'.now('UTC')->toDateString(), CapTokenSends::DAILY_CAP, 3600);

        $post = draftFor($user);
        $this->actingAs($user)->postJson("/api/v1/social-posts/{$post->ulid}/send")->assertOk();
    });

    it('does not count a failed send or a hold for approval', function () {
        Http::fake();
        $user = User::factory()->create(); // no Credits → the send is refused
        $post = draftFor($user);

        $this->actingAs($user)->withToken('cs_live_test')
            ->postJson("/api/v1/social-posts/{$post->ulid}/send", ['confirm_token' => previewToken($this, $user, $post)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('caption'); // the Credits refusal, not a missing token
        $this->actingAs($user)->withToken('cs_live_test')
            ->postJson("/api/v1/social-posts/{$post->ulid}/send", ['approval' => 'awaiting'])
            ->assertOk();

        expect(CapTokenSends::status($user)['used'])->toBe(0);
    });
});

describe('approval links from drafts', function () {
    it('shares a draft: it becomes held for the client', function () {
        Http::fake();
        $user = User::factory()->create();
        Subscription::factory()->for($user)->create();
        $post = draftFor($user);

        $this->actingAs($user)
            ->postJson('/api/v1/social-approval-links', ['approver_email' => 'client@example.test', 'post_ids' => [$post->ulid]])
            ->assertCreated()
            ->assertJsonPath('data.posts', 1);

        $post->refresh();
        expect($post->status->value)->toBe('scheduled')
            ->and($post->approval->value)->toBe('client')
            ->and($post->provider_post_id)->toBeNull();
        Http::assertNothingSent();
    });

    it('is open to Free for one client (plans v2); a second client is 402', function () {
        $user = User::factory()->create(); // Free
        $post = draftFor($user);
        $other = draftFor($user);

        $this->actingAs($user)
            ->postJson('/api/v1/social-approval-links', ['approver_email' => 'client@example.test', 'post_ids' => [$post->ulid]])
            ->assertCreated();
        $this->actingAs($user)
            ->postJson('/api/v1/social-approval-links', ['approver_email' => 'second@example.test', 'post_ids' => [$other->ulid]])
            ->assertStatus(402)
            ->assertJsonPath('feature', 'approval_clients');

        expect($post->fresh()->approval->value)->toBe('client')
            ->and($other->fresh()->status->value)->toBe('draft');
    });
});
