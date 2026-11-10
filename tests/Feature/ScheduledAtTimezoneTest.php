<?php

use App\Casts\UtcDateTime;
use App\Models\Plan;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Credits;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/*
 * scheduled_at holds UTC wall time (prod 12 Nov: 08:30 MVT stored as
 * 03:30:00) and was read back as Asia/Jakarta: every slot 7 h early in the
 * API, and a held post approved within 7 h of its slot went out at once.
 */

beforeEach(function () {
    foreach (Plan::all() as $plan) {
        $plan->update(['quotas' => [...$plan->quotas, 'social_posts_monthly' => 0, 'credits_monthly' => 0]]);
    }
    config([
        'services.social.provider' => 'outstand',
        'services.outstand.key' => 'sk_test',
        'services.outstand.base_url' => 'https://api.outstand.test/v1',
    ]);
    expect(config('app.timezone'))->toBe('Asia/Jakarta');
});

function tzUser(): User
{
    $user = User::factory()->create();
    Subscription::factory()->for($user)->create();
    Credits::grant($user, 30_000, 'Test');

    return $user;
}

function tzAccount(User $user): SocialAccount
{
    return SocialAccount::factory()->for($user)->create(['provider' => 'outstand', 'platform' => 'bluesky']);
}

function tzRaw(SocialPost $post): string
{
    return (string) DB::table('social_posts')->where('id', $post->id)->value('scheduled_at');
}

it('stores and returns 11 Nov 00:24 at UTC+5 as 10 Nov 19:24Z', function () {
    $this->travelTo('2026-11-10T12:00:00Z');
    $user = tzUser();
    $account = tzAccount($user);

    $id = $this->actingAs($user)->postJson('/api/v1/social-posts', [
        'caption' => 'Late one.',
        'account_ids' => [$account->ulid],
        'scheduled_at' => '2026-11-11T00:24:00+05:00',
        'draft' => true,
    ])->assertCreated()
        ->assertJsonPath('data.scheduled_at', '2026-11-10T19:24:00.000000Z')
        ->json('data.id');

    $post = SocialPost::query()->where('ulid', $id)->firstOrFail();
    expect(tzRaw($post))->toBe('2026-11-10 19:24:00')
        ->and($post->scheduled_at->toIso8601ZuluString())->toBe('2026-11-10T19:24:00Z');

    $this->actingAs($user)->getJson("/api/v1/social-posts/{$id}")
        ->assertJsonPath('data.scheduled_at', '2026-11-10T19:24:00.000000Z');
});

it('reads a row written as UTC, as prod holds it, at the right instant', function () {
    $this->travelTo('2026-11-11T20:00:00Z');
    $post = SocialPost::factory()->for(tzUser())->create();
    // 12 Nov 08:30 MVT (+05:00), as prod stored it.
    DB::table('social_posts')->where('id', $post->id)->update(['scheduled_at' => '2026-11-12 03:30:00']);

    expect($post->fresh()->scheduled_at->toIso8601ZuluString())->toBe('2026-11-12T03:30:00Z')
        ->and($post->fresh()->scheduled_at->isFuture())->toBeTrue();
});

it('keeps a write in any zone as UTC (a Carbon in Jakarta time, an edit with an offset)', function () {
    $this->travelTo('2026-11-10T12:00:00Z');
    $user = tzUser();
    $post = SocialPost::factory()->for($user)->create([
        'provider_post_id' => null, 'status' => 'draft',
        // 11 Nov 10:00 WIB = 03:00Z
        'scheduled_at' => now()->setTimezone('Asia/Jakarta')->setDate(2026, 11, 11)->setTime(10, 0),
    ]);
    $post->targets()->create(['social_account_id' => tzAccount($user)->id, 'status' => 'pending']);
    expect(tzRaw($post))->toBe('2026-11-11 03:00:00');

    $this->actingAs($user)->patchJson("/api/v1/social-posts/{$post->ulid}", ['scheduled_at' => '2026-11-12T09:15:00-03:00'])
        ->assertOk()
        ->assertJsonPath('data.scheduled_at', '2026-11-12T12:15:00.000000Z');
    expect(tzRaw($post))->toBe('2026-11-12 12:15:00');
});

it('does not send a held post scheduled 2 h ahead when it is approved', function () {
    $this->travelTo('2026-11-10T19:00:00Z');
    Http::fake(['api.outstand.test/*' => Http::response(['success' => true, 'post' => ['id' => 'op_1', 'publishedAt' => null]])]);
    $user = tzUser();
    $post = SocialPost::factory()->for($user)->create([
        'provider' => 'outstand', 'provider_post_id' => null, 'status' => 'scheduled', 'approval' => 'awaiting',
    ]);
    $post->targets()->create(['social_account_id' => tzAccount($user)->id, 'status' => 'pending']);
    // As the app wrote it: UTC wall time, 2 h from now.
    DB::table('social_posts')->where('id', $post->id)->update(['scheduled_at' => '2026-11-10 21:00:00']);

    $this->actingAs($user)->postJson("/api/v1/social-posts/{$post->ulid}/approve")
        ->assertOk()
        ->assertJsonPath('data.status', 'scheduled')
        ->assertJsonPath('data.scheduled_at', '2026-11-10T21:00:00.000000Z');

    // Handed over with its slot, not as "now".
    Http::assertSent(fn (HttpRequest $r) => $r->method() === 'POST'
        && str_ends_with($r->url(), '/posts')
        && ($r->data()['scheduledAt'] ?? null) === '2026-11-10T21:00:00Z');
    expect(tzRaw($post))->toBe('2026-11-10 21:00:00')
        ->and($post->fresh()->provider_post_id)->toBe('op_1');
});

it('binds UTC when a query compares the column', function () {
    $this->travelTo('2026-11-10T19:00:00Z');
    $user = tzUser();
    $soon = SocialPost::factory()->for($user)->create();
    $later = SocialPost::factory()->for($user)->create();
    DB::table('social_posts')->where('id', $soon->id)->update(['scheduled_at' => '2026-11-10 21:00:00']);
    DB::table('social_posts')->where('id', $later->id)->update(['scheduled_at' => '2026-11-11 03:00:00']);

    expect(UtcDateTime::bind(now()))->toBe('2026-11-10 19:00:00');

    // An API client's window, in its own offset: 20:00–23:00Z.
    $this->actingAs($user)->getJson('/api/v1/social-posts?'.http_build_query([
        'from' => '2026-11-11T01:00:00+05:00', 'to' => '2026-11-11T04:00:00+05:00',
    ]))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $soon->ulid);
});
