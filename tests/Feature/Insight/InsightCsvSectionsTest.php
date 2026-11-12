<?php

use App\Support\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Tests\Support\InsightFixture;

/*
 * Insight CSV, second and third files: top posts and best times, beside
 * the per-post export.csv. Documented in GET /api/v1/openapi.json.
 */

beforeEach(function () {
    Workspace::forget();
    Cache::flush();
    $this->travelTo(CarbonImmutable::parse(InsightFixture::NOW, config('app.timezone')));
    InsightFixture::fakeRelay();
});

$rows = fn (string $csv) => array_map(fn ($l) => str_getcsv($l, escape: ''), array_values(array_filter(explode("\n", $csv))));

it('downloads the top five posts as CSV, ranked like the JSON', function () use ($rows) {
    ['owner' => $owner] = InsightFixture::workspace();

    $res = $this->actingAs($owner)->get('/api/v1/insight/top-posts.csv?month=2026-10')->assertOk();
    $csv = $rows($res->streamedContent());

    expect($res->headers->get('Content-Type'))->toContain('text/csv')
        ->and($res->headers->get('Content-Disposition'))->toContain('insight-top-posts-2026-10-01-to-2026-10-31.csv')
        ->and($csv[0])->toBe(['rank', 'posted_at', 'platform', 'channel', 'engagement', 'likes', 'comments', 'shares', 'saves', 'reach', 'impressions', 'url', 'caption'])
        ->and(count($csv))->toBe(6)
        ->and($csv[1][0])->toBe('1')
        ->and($csv[1][4])->toBe('4680');

    $json = $this->actingAs($owner)->getJson('/api/v1/insight/top-posts?month=2026-10')->json('data');
    expect(array_map(fn ($r) => (int) $r[4], array_slice($csv, 1)))->toBe(array_column($json, 'engagement'));
});

it('downloads best times as CSV: one row per slot, or one not_enough_data row', function () use ($rows) {
    ['owner' => $owner] = InsightFixture::workspace();

    $csv = $rows($this->actingAs($owner)->get('/api/v1/insight/best-times.csv')->assertOk()->streamedContent());

    expect($csv[0])->toBe(['platform', 'channel', 'status', 'rank', 'weekday', 'hour', 'posts', 'avg_engagement', 'posts_analyzed']);
    $linkedin = array_values(array_filter($csv, fn ($r) => $r[0] === 'linkedin'));
    expect($linkedin)->toHaveCount(1)->and($linkedin[0][2])->toBe('not_enough_data');
    foreach (array_slice($csv, 1) as $r) {
        expect($r[2])->toBeIn(['ok', 'not_enough_data']);
    }
});

it('is Pro / Max, and documented in openapi.json', function () {
    ['owner' => $owner] = InsightFixture::workspace('free');

    $this->actingAs($owner)->get('/api/v1/insight/top-posts.csv?month=2026-10', ['Accept' => 'application/json'])->assertStatus(402);
    $this->actingAs($owner)->get('/api/v1/insight/best-times.csv', ['Accept' => 'application/json'])->assertStatus(402);

    $paths = $this->getJson('/api/v1/openapi.json')->assertOk()->json('paths');
    expect($paths)->toHaveKeys(['/insight/top-posts.csv', '/insight/best-times.csv']);
});
