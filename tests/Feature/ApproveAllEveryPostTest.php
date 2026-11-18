<?php

use App\Models\Plan;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Credits;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/*
 * HQ q202611141525-approveall: "Approve all" on a Content Plan approved 11
 * of 22 posts — the 22–28 Nov posts and one whose approval had been taken
 * back stayed waiting, and nothing said why. Now every post that asks for
 * an answer is approved, or its result says why not (outside the plan's
 * window, no time yet), across any span and any number of posts.
 */

beforeEach(function () {
    foreach (Plan::all() as $plan) {
        $plan->update(['quotas' => [...$plan->quotas, 'social_posts_monthly' => 0, 'credits_monthly' => 0]]);
    }
    config(['services.postforme.key' => 'test-key', 'services.postforme.base_url' => 'https://api.postforme.test/v1']);
    Http::fake(['api.postforme.test/*' => fn () => Http::response(['id' => 'post_'.Str::random(10), 'status' => 'scheduled'])]);
    $this->travelTo(CarbonImmutable::parse('2026-11-14 03:00:00', 'UTC'));
});

function aaOwner(bool $paid = true): User
{
    $owner = User::factory()->create();
    if ($paid) {
        Subscription::factory()->for($owner)->create();
    }
    Credits::grant($owner, 500_000, 'Test');

    return User::query()->findOrFail($owner->id);
}

/** A post waiting for the client, at a UTC instant. */
function aaPost(User $owner, SocialAccount $account, string $utc, array $attrs = []): SocialPost
{
    $post = SocialPost::factory()->for($owner)->create($attrs + [
        'provider_post_id' => null, 'status' => 'scheduled', 'approval' => 'client',
        'scheduled_at' => CarbonImmutable::parse($utc, 'UTC'), 'caption' => 'Post '.$utc,
    ]);
    $post->targets()->create(['social_account_id' => $account->id, 'status' => 'pending']);

    return $post;
}

/** A Content Plan over the dates (owner timezone Asia/Jakarta), for rani@client.test. */
function aaPlan($test, User $owner, string $from, string $to): string
{
    $url = $test->actingAs($owner)->postJson('/api/v1/social-approval-links', [
        'date_from' => $from, 'date_to' => $to, 'timezone' => 'Asia/Jakarta',
        'approver_email' => 'rani@client.test', 'title' => 'Plan',
    ])->assertCreated()->json('data.url');
    app('auth')->forgetGuards();

    return basename((string) $url);
}

it('approves every waiting post of a three-week plan in one Approve all (a paid plan: no window)', function () {
    $owner = aaOwner();
    $ig = SocialAccount::factory()->for($owner)->create(['platform' => 'instagram']);
    $posts = collect(range(0, 21))->map(fn ($d) => aaPost($owner, $ig, CarbonImmutable::parse('2026-11-15 03:00:00', 'UTC')->addDays($d)->toDateTimeString()));
    $token = aaPlan($this, $owner, '2026-11-15', '2026-12-06');

    $results = asApprover($this, 'rani@client.test')->postJson("/api/v1/a/{$token}/approve-all", ['all' => true])
        ->assertOk()->json('data.results');

    expect(collect($results)->where('approved', true))->toHaveCount(22)
        ->and($posts->map(fn ($p) => $p->fresh()->approval->value)->unique()->values()->all())->toBe(['approved'])
        ->and($posts->map(fn ($p) => $p->fresh()->approval_by)->unique()->values()->all())->toBe(['Andi']);
});

it('on a plan with a window, approves what is inside it and says, per post, from when the rest can be approved', function () {
    $owner = aaOwner(paid: false); // Free: decided at most 7 days ahead
    $ig = SocialAccount::factory()->for($owner)->create(['platform' => 'instagram']);
    $near = aaPost($owner, $ig, '2026-11-16 03:00:00');
    $soon = aaPost($owner, $ig, '2026-11-20 03:00:00');
    $later = aaPost($owner, $ig, '2026-11-24 03:00:00');
    $token = aaPlan($this, $owner, '2026-11-15', '2026-11-30');

    $results = collect(asApprover($this, 'rani@client.test')->postJson("/api/v1/a/{$token}/approve-all", ['all' => true])
        ->assertOk()->json('data.results'))->keyBy('id');

    expect($results[$near->ulid]['approved'])->toBeTrue()
        ->and($results[$soon->ulid]['approved'])->toBeTrue()
        ->and($results[$later->ulid])->toMatchArray(['approved' => false, 'reason' => 'outside_window', 'decidable_from' => '2026-11-17T03:00:00Z'])
        ->and($results[$later->ulid]['message'])->toContain('can be approved from 17 Nov')
        ->and($later->fresh()->approval->value)->toBe('client');
});

it('answers with the reasons, changing nothing, when no post can be approved yet', function () {
    $owner = aaOwner(paid: false);
    $ig = SocialAccount::factory()->for($owner)->create(['platform' => 'instagram']);
    $later = aaPost($owner, $ig, '2026-11-26 03:00:00');
    $token = aaPlan($this, $owner, '2026-11-15', '2026-11-30');

    asApprover($this, 'rani@client.test')->postJson("/api/v1/a/{$token}/approve-all", ['all' => true])
        ->assertOk()
        ->assertJsonPath('data.results.0.id', $later->ulid)
        ->assertJsonPath('data.results.0.reason', 'outside_window');
    expect($later->fresh()->approval->value)->toBe('client');
});

it('approves again a post whose approval was taken back', function () {
    $owner = aaOwner();
    $ig = SocialAccount::factory()->for($owner)->create(['platform' => 'instagram']);
    $back = aaPost($owner, $ig, '2026-11-16 03:00:00');
    $other = aaPost($owner, $ig, '2026-11-22 03:00:00');
    $token = aaPlan($this, $owner, '2026-11-15', '2026-11-30');

    asApprover($this, 'rani@client.test')->postJson("/api/v1/a/{$token}/posts/{$back->ulid}/decision", ['decision' => 'approve'])->assertOk();
    $this->postJson("/api/v1/a/{$token}/posts/{$back->ulid}/retract")->assertOk();
    expect($back->fresh()->approval->value)->toBe('client');

    $results = collect($this->postJson("/api/v1/a/{$token}/approve-all", ['all' => true])->assertOk()->json('data.results'))->keyBy('id');

    expect($results[$back->ulid]['approved'])->toBeTrue()
        ->and($results[$other->ulid]['approved'])->toBeTrue()
        ->and($back->fresh()->approval->value)->toBe('approved')
        ->and($back->fresh()->provider_post_id)->not->toBeNull();
});

it('takes more posts than one page — 60 named ids, or all: true — and approves each', function () {
    $owner = aaOwner();
    $ig = SocialAccount::factory()->for($owner)->create(['platform' => 'instagram']);
    $posts = collect(range(0, 59))->map(fn ($i) => aaPost($owner, $ig, CarbonImmutable::parse('2026-11-15 01:00:00', 'UTC')->addHours($i * 6)->toDateTimeString()));
    $token = aaPlan($this, $owner, '2026-11-15', '2026-12-31');

    asApprover($this, 'rani@client.test')->postJson("/api/v1/a/{$token}/approve-all", ['post_ids' => $posts->pluck('ulid')->all()])
        ->assertOk()->assertJsonCount(60, 'data.results');

    expect(SocialPost::query()->whereIn('id', $posts->pluck('id'))->where('approval', 'approved')->count())->toBe(60);
});
