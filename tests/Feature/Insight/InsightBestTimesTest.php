<?php

use App\Support\Insight\InsightBestTimes;
use App\Support\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Tests\Support\InsightFixture;

/*
 * Best time to post — from the channel's own posts, never a benchmark:
 * the top three weekday + hour slots by average engagement, or
 * not_enough_data under ten posts.
 */

beforeEach(function () {
    Workspace::forget();
    Cache::flush();
    $this->travelTo(CarbonImmutable::parse(InsightFixture::NOW, config('app.timezone')));
    InsightFixture::fakeRelay();
});

/** A post at $at (app timezone) with $likes likes. */
function bestTimePost(string $at, int $likes): array
{
    return [
        'posted_at' => CarbonImmutable::parse($at, config('app.timezone'))->utc(),
        'metrics' => ['likes' => $likes, 'comments' => 0, 'shares' => null, 'saves' => null],
    ];
}

it('ranks weekday + hour slots by average engagement, top three', function () {
    $posts = [
        // Tuesday 19:00 — average 300.
        bestTimePost('2026-10-06 19:10', 200), bestTimePost('2026-10-13 19:40', 400),
        // Friday 12:00 — average 250 over three posts.
        bestTimePost('2026-10-02 12:00', 250), bestTimePost('2026-10-09 12:30', 250), bestTimePost('2026-10-16 12:05', 250),
        // Sunday 09:00 — average 100.
        bestTimePost('2026-10-04 09:00', 100), bestTimePost('2026-10-11 09:00', 100),
        // Monday 08:00 — average 50.
        bestTimePost('2026-10-05 08:00', 50), bestTimePost('2026-10-12 08:00', 50), bestTimePost('2026-10-19 08:00', 50),
    ];

    $out = InsightBestTimes::of($posts);

    expect($out['status'])->toBe('ok')
        ->and($out['posts_analyzed'])->toBe(10)
        ->and(array_column($out['slots'], 'label'))->toBe(['Tue 19:00', 'Fri 12:00', 'Sun 09:00'])
        ->and($out['slots'][0])->toBe([
            'weekday' => 'Tuesday', 'weekday_index' => 2, 'hour' => 19, 'label' => 'Tue 19:00', 'posts' => 2, 'avg_engagement' => 300.0,
        ]);
});

it('says not_enough_data under ten posts with engagement, and never counts a post without it', function () {
    $posts = array_map(fn (int $d) => bestTimePost("2026-10-{$d} 10:00", 10), range(10, 18));
    $posts[] = ['posted_at' => CarbonImmutable::parse('2026-10-20 10:00'), 'metrics' => ['likes' => null, 'comments' => null, 'shares' => null, 'saves' => null]];

    expect(InsightBestTimes::of($posts))->toBe(['status' => 'not_enough_data', 'posts_analyzed' => 9, 'min_posts' => 10, 'slots' => []])
        ->and(InsightBestTimes::of(null)['status'])->toBe('not_enough_data');
});

it('answers per channel over the last 90 days, labelled as from your posts', function () {
    ['owner' => $owner, 'accounts' => $accounts] = InsightFixture::workspace();

    $res = $this->actingAs($owner)->getJson('/api/v1/insight/best-times')->assertOk();

    expect($res->json('meta.basis'))->toBe('from your posts')
        ->and($res->json('meta.min_posts'))->toBe(10)
        ->and($res->json('meta.timezone'))->toBe(config('app.timezone'));
    $channels = collect($res->json('data'))->keyBy('platform');
    expect($channels->keys()->all())->toContain('instagram', 'linkedin');
    foreach ($channels as $c) {
        expect($c['status'])->toBeIn(['ok', 'not_enough_data'])
            ->and(count($c['slots']))->toBeLessThanOrEqual(3);
        if ($c['status'] === 'not_enough_data') {
            expect($c['slots'])->toBe([])->and($c['posts_analyzed'])->toBeLessThan(10);
        }
    }
    // LinkedIn's feed fails in the fixture: nothing analysed, not a guess.
    expect($channels['linkedin']['status'])->toBe('not_enough_data')
        ->and($channels['linkedin']['posts_analyzed'])->toBe(0);
});

it('keeps to the channels and days asked for, and validates them', function () {
    ['owner' => $owner, 'accounts' => $accounts] = InsightFixture::workspace();
    $ig = $accounts['instagram']->ulid;

    $res = $this->actingAs($owner)->getJson("/api/v1/insight/best-times?days=30&channel_ids={$ig}")->assertOk();
    expect(array_column($res->json('data'), 'channel_id'))->toBe([$ig])
        ->and(CarbonImmutable::parse($res->json('meta.period.from'))->diffInDays(CarbonImmutable::parse($res->json('meta.period.to'))))->toEqual(29);

    $this->actingAs($owner)->getJson('/api/v1/insight/best-times?days=400')->assertUnprocessable()->assertJsonValidationErrors('days');
    $this->actingAs($owner)->getJson('/api/v1/insight/best-times?channel_ids=01ZZZZZZZZZZZZZZZZZZZZZZZZ')->assertUnprocessable()->assertJsonValidationErrors('channel_ids');
});

it('is Pro / Max: Free gets 402 plan_required', function () {
    ['owner' => $owner] = InsightFixture::workspace('free');

    $this->actingAs($owner)->getJson('/api/v1/insight/best-times')
        ->assertStatus(402)->assertJsonPath('reason', 'plan_required');
});
