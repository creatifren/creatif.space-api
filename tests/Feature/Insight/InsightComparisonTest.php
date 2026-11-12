<?php

use App\Support\Insight\InsightCollector;
use App\Support\Insight\InsightPeriod;
use App\Support\Insight\InsightView;
use App\Support\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Tests\Support\InsightFixture;

/*
 * Period comparison: every Insight number comes with the previous period of
 * the same length and its change in percent — null, never 0, when either
 * side has no number or the previous period published nothing.
 */

beforeEach(function () {
    Workspace::forget();
    Cache::flush();
    $this->travelTo(CarbonImmutable::parse(InsightFixture::NOW, config('app.timezone')));
    InsightFixture::fakeRelay();
});

it('pairs every total and every channel number with the previous period and a change', function () {
    ['owner' => $owner] = InsightFixture::workspace();

    $data = $this->actingAs($owner)->getJson('/api/v1/insight/summary?month=2026-10')->assertOk()->json('data');

    $metrics = ['posts', 'reach', 'impressions', 'engagement', 'engagement_rate', 'likes', 'comments', 'shares', 'saves'];
    expect(array_keys($data['change_pct']))->toBe($metrics);
    foreach ($metrics as $m) {
        expect($data['totals'])->toHaveKey($m)
            ->and($data['totals_previous'])->toHaveKey($m);
    }

    foreach ($data['channels'] as $c) {
        expect(array_keys($c['change_pct']))->toBe($metrics)
            ->and(array_keys($c['previous']))->toBe($metrics)
            ->and($c['followers'])->toHaveKey('previous_change');
    }

    expect(array_keys($data['workflow']['change_pct']))->toBe(['posts_approved', 'avg_approval_hours', 'on_time_rate'])
        // 3 approved in October against 1 in September.
        ->and($data['workflow']['change_pct']['posts_approved'])->toEqual(200);
});

it('computes the change from the two periods, and leaves it null without a base', function () {
    ['owner' => $owner, 'accounts' => $accounts] = InsightFixture::workspace();

    $data = $this->actingAs($owner)->getJson('/api/v1/insight/summary?month=2026-10')->assertOk()->json('data');

    $likes = $data['totals']['likes'];
    $before = $data['totals_previous']['likes'];
    expect($data['change_pct']['likes'])->toBe(round(($likes - $before) / $before * 100, 1));

    $channels = collect($data['channels'])->keyBy('platform');
    // Facebook reports no saves, so no change either — null, not 0.
    expect($channels['facebook']['saves'])->toBeNull()
        ->and($channels['facebook']['change_pct']['saves'])->toBeNull()
        // X published nothing in September: nothing to compare against.
        ->and($channels['x']['change_pct']['likes'])->toBeNull()
        ->and($channels['instagram']['followers']['previous_change'])->toBeInt();
});

it('prints the change beside each figure on the one-page PDF', function () {
    ['owner' => $owner, 'accounts' => $accounts] = InsightFixture::workspace();
    $period = InsightPeriod::month('2026-10');

    $data = app(InsightCollector::class)->collectCached($owner, collect(array_values($accounts)), $period);

    expect($data['totals']['previous']['posts'])->toBeGreaterThan(0);
    $view = InsightView::build($data, [
        'client_name' => 'Kedai Ibu', 'locale' => 'en', 'logo' => null, 'branding' => true, 'notes' => null,
        'generated_at' => CarbonImmutable::now(), 'thumbnails' => [],
    ]);
    foreach (array_slice($view['kpis'], 0, 3) as $kpi) {
        expect($kpi['delta'])->not->toBeNull();
    }
});
