<?php

use App\Models\Plan;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Credits;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;

/*
 * POST /social-posts/send-batch (MCP schedule_posts): up to 10 drafts
 * scheduled together, all or none. Checked first, sent one by one, and a
 * refusal on the way takes back the ones already handed over.
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

/** A paid user (no 7-day window, no 10-post queue limit) with Credits. */
function sbUser(int $credits = 100_000): User
{
    $user = User::factory()->create();
    Subscription::factory()->for($user)->create();
    $user = User::query()->findOrFail($user->id);
    Credits::grant($user, $credits, 'Test');

    return $user;
}

function sbDraft(User $user, string $platform = 'instagram'): SocialPost
{
    $post = SocialPost::factory()->for($user)->create([
        'status' => 'draft', 'provider_post_id' => null, 'scheduled_at' => null, 'approval' => null, 'caption' => 'Batch '.str()->random(6),
    ]);
    $post->targets()->create([
        'social_account_id' => SocialAccount::factory()->for($user)->create(['platform' => $platform])->id,
        'status' => 'pending',
    ]);

    return $post;
}

function sbToken($test, User $user, SocialPost $post): string
{
    return $test->actingAs($user)->getJson("/api/v1/social-posts/{$post->ulid}/send-preview")->assertOk()->json('data.confirm_token');
}

/** Items for the batch, each with a fresh preview token, a day apart. */
function sbItems($test, User $user, array $posts): array
{
    return array_map(fn (SocialPost $p, int $i) => [
        'post_id' => $p->ulid,
        'scheduled_at' => now()->addDays($i + 1)->toIso8601String(),
        'confirm_token' => sbToken($test, $user, $p),
    ], $posts, array_keys($posts));
}

function sbSend($test, User $user, array $items)
{
    return $test->actingAs($user)->withToken('cs_live_test')->postJson('/api/v1/social-posts/send-batch', ['posts' => $items]);
}

/** The relay: creates succeed, except the $failOn-th (1-based); deletes succeed. */
function sbRelay(?int $failOn = null): void
{
    $creates = 0;
    Http::fake(['api.postforme.test/*' => function (HttpRequest $r) use (&$creates, $failOn) {
        if ($r->method() === 'DELETE') {
            return Http::response(['ok' => true]);
        }
        $creates++;

        return $creates === $failOn
            ? Http::response(['message' => 'boom'], 500)
            : Http::response(['id' => 'post_'.str()->random(10), 'status' => 'scheduled']);
    }]);
}

it('schedules every post of the batch', function () {
    sbRelay();
    $user = sbUser();
    $posts = [sbDraft($user), sbDraft($user, 'x'), sbDraft($user, 'linkedin')];
    $before = Credits::total($user);

    sbSend($this, $user, sbItems($this, $user, $posts))->assertOk()->assertJsonCount(3, 'data.scheduled');

    foreach ($posts as $p) {
        expect($p->fresh()->status->value)->toBe('scheduled')->and($p->fresh()->provider_post_id)->not->toBeNull();
    }
    expect(Credits::total($user))->toBeLessThan($before);
});

it('partial failure rolls back: the posts already handed over are recalled, refunded and back to draft', function () {
    sbRelay(failOn: 2);
    $user = sbUser();
    $posts = [sbDraft($user), sbDraft($user), sbDraft($user)];
    $before = Credits::total($user);

    sbSend($this, $user, sbItems($this, $user, $posts))
        ->assertStatus(409)
        ->assertJsonPath('code', 'batch_failed')
        ->assertJsonPath('failed_post_id', $posts[1]->ulid)
        ->assertJsonPath('rolled_back', [$posts[0]->ulid])
        ->assertJsonPath('rollback_failed', []);

    foreach ($posts as $p) {
        $fresh = $p->fresh();
        expect($fresh->status->value)->toBe('draft')
            ->and($fresh->provider_post_id)->toBeNull()
            ->and($fresh->scheduled_at)->toBeNull();
    }
    // Every Credit came back, and the first post was deleted at the relay.
    expect(Credits::total($user))->toBe($before);
    Http::assertSent(fn (HttpRequest $r) => $r->method() === 'DELETE');
    // The third was never sent.
    Http::assertNotSent(fn (HttpRequest $r) => $r->method() === 'POST' && str_contains($r->body(), $posts[2]->caption));
});

it('refuses the whole batch before sending anything when one post is not a draft or its preview is stale', function () {
    sbRelay();
    $user = sbUser();
    $a = sbDraft($user);
    $b = sbDraft($user);
    $items = sbItems($this, $user, [$a, $b]);
    $b->update(['status' => 'scheduled', 'provider_post_id' => 'post_x']);

    sbSend($this, $user, $items)->assertStatus(409)
        ->assertJsonPath('code', 'not_schedulable')
        ->assertJsonPath('refused.0.post_id', $b->ulid)
        ->assertJsonPath('refused.0.code', 'not_draft');
    expect($a->fresh()->status->value)->toBe('draft');
    Http::assertNothingSent();

    $c = sbDraft($user);
    $items = sbItems($this, $user, [$c]);
    $items[0]['confirm_token'] = 'stale.token';
    sbSend($this, $user, $items)->assertStatus(409)->assertJsonPath('code', 'preview_stale');
    Http::assertNothingSent();
});

it('refuses a batch the Credits do not cover, before the first charge', function () {
    sbRelay();
    $user = sbUser(credits: 0);
    $posts = [sbDraft($user), sbDraft($user)];

    sbSend($this, $user, sbItems($this, $user, $posts))->assertStatus(422)->assertJsonPath('code', 'not_enough_credits');
    Http::assertNothingSent();
});

it('counts every post against the 20 connected-app sends a day, all or none', function () {
    sbRelay();
    $user = sbUser();
    $posts = array_map(fn () => sbDraft($user), range(1, 10));
    sbSend($this, $user, sbItems($this, $user, $posts))->assertOk();
    $more = array_map(fn () => sbDraft($user), range(1, 10));
    sbSend($this, $user, sbItems($this, $user, $more))->assertOk();

    $last = [sbDraft($user)];
    sbSend($this, $user, sbItems($this, $user, $last))->assertStatus(429)->assertJsonPath('code', 'daily_send_cap')->assertJsonPath('remaining', 0);
    expect($last[0]->fresh()->status->value)->toBe('draft');
});

it('takes at most 10 posts, each once, and only the caller\'s own', function () {
    sbRelay();
    $user = sbUser();
    $other = sbDraft(sbUser());
    $mine = sbDraft($user);

    sbSend($this, $user, [['post_id' => $other->ulid, 'scheduled_at' => now()->addDay()->toIso8601String(), 'confirm_token' => 'x']])
        ->assertNotFound()->assertJsonPath('code', 'not_found');

    $item = ['post_id' => $mine->ulid, 'scheduled_at' => now()->addDay()->toIso8601String(), 'confirm_token' => 'x'];
    sbSend($this, $user, [$item, $item])->assertUnprocessable()->assertJsonValidationErrors('posts.0.post_id');
    sbSend($this, $user, array_fill(0, 11, $item))->assertUnprocessable()->assertJsonValidationErrors('posts');
    Http::assertNothingSent();
});

it('is in the MCP token fence with the publish scope, like a single send', function () {
    expect(config('mcp.routes')['api.v1.social-posts.send-batch'])->toBe('publish')
        ->and(config('mcp.routes')['api.v1.social-posts.send'])->toBe('publish');
});

it('holds a Free plan to its queue room for the whole batch', function () {
    sbRelay();
    $user = User::factory()->create();
    Credits::grant($user, 100_000, 'Test');
    $posts = array_map(fn () => sbDraft($user), range(1, 3));
    SocialPost::factory()->for($user)->count(9)->create(['status' => 'scheduled', 'provider_post_id' => null, 'approval' => 'client', 'scheduled_at' => now()->addDays(2)]);
    $items = array_map(fn (SocialPost $p) => [
        'post_id' => $p->ulid, 'scheduled_at' => now()->addDays(2)->toIso8601String(), 'confirm_token' => sbToken($this, $user, $p),
    ], $posts);

    sbSend($this, $user, $items)->assertStatus(402);
    Http::assertNothingSent();
    foreach ($posts as $p) {
        expect($p->fresh()->status->value)->toBe('draft');
    }
});

it('runs once per Idempotency-Key: a repeated request replays, nothing is sent twice', function () {
    sbRelay();
    $user = sbUser();
    $items = sbItems($this, $user, [sbDraft($user)]);

    $first = $this->actingAs($user)->withToken('cs_live_test')->withHeader('Idempotency-Key', 'batch-1')
        ->postJson('/api/v1/social-posts/send-batch', ['posts' => $items])->assertOk();
    $again = $this->actingAs($user)->withToken('cs_live_test')->withHeader('Idempotency-Key', 'batch-1')
        ->postJson('/api/v1/social-posts/send-batch', ['posts' => $items])->assertOk()->assertHeader('Idempotent-Replayed', 'true');

    expect($again->json())->toBe($first->json());
    expect(Http::recorded(fn (HttpRequest $r) => $r->method() === 'POST')->count())->toBe(1);
});
