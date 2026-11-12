<?php

use App\Support\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Tests\Support\InsightFixture;

/*
 * GET /insight/top-posts — the top five posts of a period by engagement,
 * read by the MCP tool list_top_posts.
 */

beforeEach(function () {
    Workspace::forget();
    Cache::flush();
    $this->travelTo(CarbonImmutable::parse(InsightFixture::NOW, config('app.timezone')));
    InsightFixture::fakeRelay();
});

it('ranks the five most engaging posts of the month, highest first', function () {
    ['owner' => $owner] = InsightFixture::workspace();

    $res = $this->actingAs($owner)->getJson('/api/v1/insight/top-posts?month=2026-10')->assertOk();

    $posts = $res->json('data');
    expect($posts)->toHaveCount(5)
        ->and($posts[0]['engagement'])->toBe(4680)
        ->and($posts[0]['network'])->toBe('TikTok')
        ->and($res->json('meta'))->toBe(['period' => ['from' => '2026-10-01', 'to' => '2026-10-31'], 'ranked_by' => 'engagement', 'limit' => 5]);

    $engagement = array_column($posts, 'engagement');
    $sorted = $engagement;
    rsort($sorted);
    expect($engagement)->toBe($sorted);
    foreach ($posts as $p) {
        expect($p)->toHaveKeys(['channel_id', 'platform', 'post_id', 'posted_at', 'caption', 'url', 'thumbnail_url', 'likes', 'comments', 'shares', 'saves', 'reach', 'impressions'])
            ->and($p['engagement'])->toBeInt();
        expect(CarbonImmutable::parse($p['posted_at'])->between(
            CarbonImmutable::parse('2026-10-01', config('app.timezone')),
            CarbonImmutable::parse('2026-10-31 23:59:59', config('app.timezone')),
        ))->toBeTrue();
    }
});

it('keeps to the channels asked for', function () {
    ['owner' => $owner, 'accounts' => $accounts] = InsightFixture::workspace();
    $ig = $accounts['instagram']->ulid;

    $posts = $this->actingAs($owner)->getJson("/api/v1/insight/top-posts?month=2026-10&channel_ids={$ig}")->assertOk()->json('data');

    expect($posts)->not->toBeEmpty()
        ->and(array_unique(array_column($posts, 'channel_id')))->toBe([$ig]);
});

it('refuses a channel outside the workspace and a bad period', function () {
    ['owner' => $owner] = InsightFixture::workspace();

    $this->actingAs($owner)->getJson('/api/v1/insight/top-posts?month=2026-10&channel_ids=01ZZZZZZZZZZZZZZZZZZZZZZZZ')
        ->assertUnprocessable()->assertJsonValidationErrors('channel_ids');
    $this->actingAs($owner)->getJson('/api/v1/insight/top-posts?month=2026-13')
        ->assertUnprocessable()->assertJsonValidationErrors('month');
});

it('is Pro / Max: Free gets 402 plan_required', function () {
    ['owner' => $owner] = InsightFixture::workspace('free');

    $this->actingAs($owner)->getJson('/api/v1/insight/top-posts?month=2026-10')
        ->assertStatus(402)
        ->assertJsonPath('reason', 'plan_required')
        ->assertJsonPath('feature', 'insight');
});

/*
 * B6: a post that went out through creatif.space carries our post id, as in
 * the Insight CSV — matched by the relay's post id when the feed has no
 * external id — in list_top_posts and get_insight_summary.top_posts.
 */
it('names our post id for a post made through creatif.space, null for one made elsewhere', function () {
    ['owner' => $owner, 'accounts' => $accounts] = InsightFixture::workspace();
    $ig = $accounts['instagram']->ulid;
    // The fixture's 14 Oct post went out through us as relay post op_instagram_oct_4.
    $ours = \App\Models\SocialPost::query()->where('provider_post_id', 'op_instagram_oct_4')->sole();

    $posts = $this->actingAs($owner)->getJson("/api/v1/insight/top-posts?month=2026-10&channel_ids={$ig}")->assertOk()->json('data');
    $giveaway = collect($posts)->first(fn ($p) => str_starts_with($p['caption'], 'Giveaway!'));
    expect($giveaway['post_id'])->toBe($ours->ulid);
    // Only the fixture's posts made through us (relay ids op_instagram_oct_2/4/9) are named.
    $throughUs = \App\Models\SocialPost::query()->whereIn('provider_post_id', ['op_instagram_oct_2', 'op_instagram_oct_4', 'op_instagram_oct_9'])->pluck('ulid')->all();
    expect(array_diff(collect($posts)->pluck('post_id')->filter()->all(), $throughUs))->toBe([]);

    $tiktok = $this->actingAs($owner)->getJson('/api/v1/insight/top-posts?month=2026-10&channel_ids='.$accounts['tiktok']->ulid)->assertOk()->json('data');
    expect(array_unique(array_column($tiktok, 'post_id')))->toBe([null]);

    $top = $this->actingAs($owner)->getJson("/api/v1/insight/summary?month=2026-10&channel_ids={$ig}")->assertOk()->json('data.top_posts');
    $inSummary = collect($top)->first(fn ($p) => str_starts_with($p['caption'], 'Giveaway!'));
    expect($inSummary)->toMatchArray(['post_id' => $ours->ulid, 'channel_id' => $ig]);
});

it('never puts our post or channel ids on a public report', function () {
    $public = \App\Http\Controllers\Api\V1\PublicInsightController::publicView([
        'channels' => [['id' => 'c1', 'network' => 'Instagram']],
        'top_posts' => [['post_id' => '01JPOST', 'channel_id' => 'c1', 'network' => 'Instagram', 'engagement' => 9]],
    ]);

    expect($public['top_posts'][0])->toBe(['network' => 'Instagram', 'engagement' => 9])
        ->and($public['channels'][0])->not->toHaveKey('id');
});

it('does not name another workspace’s post that has the same relay id', function () {
    ['owner' => $owner, 'accounts' => $accounts] = InsightFixture::workspace();
    \App\Models\SocialPost::query()->where('provider_post_id', 'op_instagram_oct_4')->update(['user_id' => \App\Models\User::factory()->create()->id]);

    $posts = $this->actingAs($owner)->getJson('/api/v1/insight/top-posts?month=2026-10&channel_ids='.$accounts['instagram']->ulid)->assertOk()->json('data');
    expect(collect($posts)->first(fn ($p) => str_starts_with($p['caption'], 'Giveaway!'))['post_id'])->toBeNull();
});
