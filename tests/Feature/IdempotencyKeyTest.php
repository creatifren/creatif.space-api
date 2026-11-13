<?php

use App\Http\Middleware\CapTokenSends;
use App\Http\Middleware\IdempotencyKey;
use App\Models\Plan;
use App\Models\SocialAccount;
use App\Models\SocialApprovalLink;
use App\Models\SocialPost;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Credits;
use App\Support\SendConfirmation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/*
 * Item 7: an Idempotency-Key on publish / schedule (POST send), retry and
 * approval-link create runs the request once for 24 hours; the same key
 * replays the first response. The double submit: one send, one charge,
 * one link.
 */

beforeEach(function () {
    foreach (Plan::all() as $plan) {
        $plan->update(['quotas' => [...$plan->quotas, 'social_posts_monthly' => 0, 'credits_monthly' => 0]]);
    }
    config([
        'services.postforme.key' => 'test-key',
        'services.postforme.base_url' => 'https://api.postforme.test/v1',
    ]);
    Cache::flush();
    Http::fake(['api.postforme.test/*' => fn () => Http::response(['id' => 'post_'.str()->random(10), 'status' => 'scheduled'])]);
});

function ikUser(): User
{
    $user = User::factory()->create();
    Subscription::factory()->for($user)->create();
    Credits::grant($user, 100_000, 'Test');

    return $user;
}

function ikDraft(User $user): SocialPost
{
    $post = SocialPost::factory()->for($user)->create([
        'status' => 'draft', 'provider_post_id' => null, 'scheduled_at' => null, 'caption' => 'Winter set',
    ]);
    $post->targets()->create([
        'social_account_id' => SocialAccount::factory()->for($user)->create(['platform' => 'instagram'])->id,
        'status' => 'pending',
    ]);

    return $post;
}

function ikSend($test, User $user, SocialPost $post, string $key, array $body = [])
{
    return $test->actingAs($user)->postJson("/api/v1/social-posts/{$post->ulid}/send", $body, ['Idempotency-Key' => $key]);
}

describe('double submit', function () {
    it('publishes once: the second request replays the first response', function () {
        $user = ikUser();
        $post = ikDraft($user);
        $before = Credits::balance($user);

        $first = ikSend($this, $user, $post, 'k-publish-1')->assertOk();
        $second = ikSend($this, $user, $post, 'k-publish-1')->assertOk()->assertHeader('Idempotent-Replayed', 'true');

        expect($second->getContent())->toBe($first->getContent())
            ->and($first->headers->has('Idempotent-Replayed'))->toBeFalse();
        Http::assertSentCount(1);
        expect(Credits::balance($user))->toBe($before - Credits::perSend('instagram'));

        // Without the key the second one is a real request again: a sent post is not a draft.
        $this->actingAs($user)->postJson("/api/v1/social-posts/{$post->ulid}/send")->assertUnprocessable();
    });

    it('schedules once', function () {
        $user = ikUser();
        $post = ikDraft($user);
        $body = ['scheduled_at' => now()->addDay()->toIso8601String()];

        ikSend($this, $user, $post, 'k-schedule', $body)->assertOk()->assertJsonPath('data.status', 'scheduled');
        ikSend($this, $user, $post, 'k-schedule', $body)->assertOk()->assertHeader('Idempotent-Replayed', 'true');

        Http::assertSentCount(1);
    });

    it('retries once', function () {
        config(['services.social.provider' => 'outstand', 'services.outstand.key' => 'sk', 'services.outstand.base_url' => 'https://api.outstand.test/v1']);
        Http::fake(['api.outstand.test/v1/posts' => Http::response(['success' => true, 'post' => ['id' => 'op_r', 'publishedAt' => null]])]);
        $user = ikUser();
        $account = SocialAccount::factory()->for($user)->create(['platform' => 'pinterest', 'provider' => 'outstand']);
        $post = SocialPost::factory()->for($user)->create(['provider' => 'outstand', 'provider_post_id' => 'op_1', 'status' => 'failed', 'scheduled_at' => null]);
        $post->targets()->create(['social_account_id' => $account->id, 'status' => 'failed', 'fail_code' => 'platform_unavailable', 'fail_reason' => 'Pinterest was unavailable.']);

        $retry = fn () => $this->actingAs($user)->postJson("/api/v1/social-posts/{$post->ulid}/retry", [], ['Idempotency-Key' => 'k-retry']);
        $retry()->assertOk()->assertJsonPath('retried', 1);
        // Not 409 not_failed: the replay of the first answer.
        $retry()->assertOk()->assertJsonPath('retried', 1)->assertHeader('Idempotent-Replayed', 'true');

        Http::assertSentCount(1);
    });

    it('creates one approval link', function () {
        $user = ikUser();
        $post = ikDraft($user);
        $post->update(['scheduled_at' => now()->addDay()]);
        $create = fn () => $this->actingAs($user)
            ->postJson('/api/v1/social-approval-links', ['approver_email' => 'client@example.test', 'post_ids' => [$post->ulid]], ['Idempotency-Key' => 'k-link']);

        $id = $create()->assertCreated()->json('data.id');
        $create()->assertCreated()->assertJsonPath('data.id', $id)->assertHeader('Idempotent-Replayed', 'true');

        expect(SocialApprovalLink::query()->where('user_id', $user->id)->count())->toBe(1);
    });

    it('does not count a replay against the connected-app daily cap', function () {
        $user = ikUser();
        $post = ikDraft($user);
        $token = SendConfirmation::issue($user, $post);
        $send = fn () => $this->actingAs($user)->withToken('cs_live_test')
            ->postJson("/api/v1/social-posts/{$post->ulid}/send", ['confirm_token' => $token], ['Idempotency-Key' => 'k-cap']);

        $send()->assertOk();
        $send()->assertOk()->assertHeader('Idempotent-Replayed', 'true');

        expect(CapTokenSends::status($user)['used'])->toBe(1);
        Http::assertSentCount(1);
    });
});

describe('the key', function () {
    it('names one request: the same key for another post or body is 422', function () {
        $user = ikUser();
        $a = ikDraft($user);
        $b = ikDraft($user);

        ikSend($this, $user, $a, 'k-one')->assertOk();
        ikSend($this, $user, $b, 'k-one')->assertUnprocessable()->assertJsonPath('code', 'idempotency_key_reused');
        ikSend($this, $user, $a, 'k-one', ['scheduled_at' => now()->addDay()->toIso8601String()])
            ->assertUnprocessable()->assertJsonPath('code', 'idempotency_key_reused');

        expect($b->fresh()->status->value)->toBe('draft');
        Http::assertSentCount(1);
    });

    it('is per user', function () {
        $alice = ikUser();
        $bob = ikUser();

        ikSend($this, $alice, ikDraft($alice), 'shared-key')->assertOk();
        ikSend($this, $bob, ikDraft($bob), 'shared-key')->assertOk()->assertHeaderMissing('Idempotent-Replayed');

        Http::assertSentCount(2);
    });

    it('keeps the response for 24 hours', function () {
        $user = ikUser();
        $post = ikDraft($user);

        ikSend($this, $user, $post, 'k-ttl')->assertOk();
        $this->travel(23)->hours();
        ikSend($this, $user, $post, 'k-ttl')->assertOk()->assertHeader('Idempotent-Replayed', 'true');
        $this->travel(2)->hours();
        // Gone: the request runs again (and a sent post is no draft).
        ikSend($this, $user, $post, 'k-ttl')->assertUnprocessable();

        Http::assertSentCount(1);
    });

    it('keeps only a success: a refused request can be sent again with the same key', function () {
        $user = ikUser();
        $post = ikDraft($user);
        $send = fn (array $body) => $this->actingAs($user)->withToken('cs_live_test')
            ->postJson("/api/v1/social-posts/{$post->ulid}/send", $body, ['Idempotency-Key' => 'k-fix']);

        $send([])->assertUnprocessable()->assertJsonPath('code', 'confirm_token_required');
        $send(['confirm_token' => SendConfirmation::issue($user, $post)])->assertOk()->assertHeaderMissing('Idempotent-Replayed');
    });

    it('answers 409 idempotency_in_progress while the first is still running', function () {
        $user = ikUser();
        $post = ikDraft($user);
        $lock = Cache::lock('idem:'.$user->id.':api.v1.social-posts.send:'.hash('sha256', 'k-busy').':lock', 60);
        expect($lock->get())->toBeTrue();

        ikSend($this, $user, $post, 'k-busy')->assertStatus(409)->assertJsonPath('code', 'idempotency_in_progress')->assertHeader('Retry-After', '2');
        Http::assertNothingSent();

        $lock->release();
        ikSend($this, $user, $post, 'k-busy')->assertOk();
    });

    it('refuses a malformed key, and changes nothing without one', function () {
        $user = ikUser();
        $post = ikDraft($user);

        ikSend($this, $user, $post, str_repeat('x', 256))->assertUnprocessable()->assertJsonPath('code', 'invalid_idempotency_key');
        Http::assertNothingSent();

        $this->actingAs($user)->postJson("/api/v1/social-posts/{$post->ulid}/send")->assertOk()->assertHeaderMissing('Idempotent-Replayed');
    });

    it('is on exactly the send, retry and approval-link create routes', function () {
        $routes = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($r) => in_array(IdempotencyKey::class, $r->gatherMiddleware(), true))
            ->map(fn ($r) => $r->getName())->sort()->values()->all();

        expect($routes)->toBe(['api.v1.social-approval-links.store', 'api.v1.social-posts.retry', 'api.v1.social-posts.send', 'api.v1.social-posts.send-batch']);
    });
});
