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
