<?php

use App\Models\FollowerSnapshot;
use App\Support\Insight\InsightStrings;
use App\Support\Insight\InsightThresholds;
use App\Support\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Tests\Support\InsightFixture;

/*
 * Follower-threshold notes (bug q2009): a metric a network holds back until
 * the account has enough followers gets `below_threshold` with the official
 * number and the page that states it — never a vendor name, never a 0.
 */

beforeEach(function () {
    Workspace::forget();
    Cache::flush();
    $this->travelTo(CarbonImmutable::parse(InsightFixture::NOW, config('app.timezone')));
    InsightFixture::fakeRelay();
});

dataset('ruled networks', [
    'Instagram' => ['instagram', 'Instagram', 'https://developers.facebook.com/docs/instagram-platform/insights/'],
    'Threads' => ['threads', 'Threads', 'https://developers.facebook.com/docs/threads/insights/'],
    'Facebook' => ['facebook', 'Facebook', 'https://developers.facebook.com/docs/graph-api/reference/v24.0/insights'],
]);

it('notes audience data below 100 followers, with the official source', function (string $platform, string $network, string $source) {
    $notes = InsightThresholds::notes($platform, 99, $network);

    expect($notes)->toHaveCount(1)
        ->and($notes[0])->toMatchArray([
            'key' => 'below_threshold',
            'metric' => 'audience',
            'reason' => 'below_threshold',
            'threshold' => 100,
            'source_url' => $source,
        ])
        ->and(InsightThresholds::notes($platform, 100, $network))->toBe([])
        ->and(InsightThresholds::notes($platform, null, $network))->toBe([]);
})->with('ruled networks');

it('gives no threshold note for a network without an official number', function (string $platform) {
    expect(InsightThresholds::notes($platform, 5, ucfirst($platform)))->toBe([]);
})->with(['tiktok', 'x', 'linkedin', 'youtube', 'pinterest', 'bluesky']);

it('keeps every rule sourced from the platform itself, with no vendor in it', function () {
    foreach (InsightThresholds::RULES as $rule) {
        expect($rule['source'])->toStartWith('https://developers.facebook.com/')
            ->and($rule['min'])->toBeGreaterThan(0);
    }
});

it('returns the note per channel in the summary, and only under the threshold', function () {
    ['owner' => $owner, 'accounts' => $accounts] = InsightFixture::workspace();
    // Facebook ends October at 80 followers; Instagram stays at 13,150.
    FollowerSnapshot::query()->where('social_account_id', $accounts['facebook']->id)->update(['followers' => 80]);

    $res = $this->actingAs($owner)->getJson('/api/v1/insight/summary?month=2026-10')->assertOk();

    $channels = collect($res->json('data.channels'))->keyBy('platform');
    expect($channels['facebook']['notes'])->toBe([[
        'metric' => 'audience',
        'reason' => 'below_threshold',
        'threshold' => 100,
        'source_url' => 'https://developers.facebook.com/docs/graph-api/reference/v24.0/insights',
        'text' => 'Audience data: Facebook shares this once an account has at least 100 followers.',
    ]])
        ->and($channels['instagram']['notes'])->toBe([])
        ->and($channels['tiktok']['notes'])->toBe([])
        ->and($res->json('data.notes'))->toContain('Audience data: Facebook shares this once an account has at least 100 followers.');

    $text = json_encode($res->json('data'));
    expect($text)->not->toContain('Outstand')->not->toContain('Post for Me');
});

it('prints the note in Indonesian on the report', function () {
    expect(InsightStrings::get('id', 'below_threshold', [
        'metric' => InsightStrings::get('id', 'audience'),
        'network' => 'Instagram',
        'threshold' => '100',
    ]))->toBe('Data audiens: Instagram membagikan data ini setelah akun punya minimal 100 pengikut.');
});
