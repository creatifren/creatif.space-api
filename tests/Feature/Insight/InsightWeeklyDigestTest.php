<?php

use App\Notifications\WeeklyDigest as WeeklyDigestMail;
use App\Support\WeeklyDigest;
use App\Support\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Tests\Support\InsightFixture;

/*
 * The weekly digest's Insight section (Pro / Max): last week's reach and
 * engagement against the week before, the top post and the best time to
 * post from the user's own posts — never on Free, never a guess, no mascot,
 * the slogan footer from the mail layout.
 */

beforeEach(function () {
    Workspace::forget();
    Cache::flush();
    $this->travelTo(CarbonImmutable::parse(InsightFixture::NOW, config('app.timezone')));
    InsightFixture::fakeRelay();
    config(['app.frontend_url' => 'https://app.creatif.test']);
});

$week = fn () => [CarbonImmutable::parse('2026-10-19', config('app.timezone')), CarbonImmutable::parse('2026-10-25', config('app.timezone'))];

it('gives last week against the week before, the top post and the best slot', function () use ($week) {
    ['owner' => $owner] = InsightFixture::workspace();
    [$from, $to] = $week();

    $insight = WeeklyDigest::insight($owner, $from, $to);

    expect($insight)->not->toBeNull()
        ->and($insight['reach'])->toBeInt()
        ->and($insight['engagement'])->toBeInt()
        ->and(array_key_exists('reach_change', $insight))->toBeTrue()
        ->and($insight['top_post'])->toHaveKeys(['network', 'caption', 'url', 'engagement']);
    if ($insight['best_time'] !== null) {
        expect($insight['best_time']['label'])->toMatch('/^(Mon|Tue|Wed|Thu|Fri|Sat|Sun) \d\d:00$/');
    }
});

it('has no Insight section on Free', function () use ($week) {
    ['owner' => $owner] = InsightFixture::workspace('free');
    [$from, $to] = $week();

    expect(WeeklyDigest::insight($owner, $from, $to))->toBeNull();
});

it('writes the section into the email, labelled from your posts, with no mascot', function () {
    ['owner' => $owner] = InsightFixture::workspace();
    $digest = [
        'week' => ['from' => '2026-10-19', 'to' => '2026-10-25', 'timezone' => 'Asia/Jakarta'],
        'published' => 4, 'waiting' => 0, 'failed' => [], 'failed_total' => 0,
        'insight' => [
            'reach' => 12400, 'reach_change' => 18.5, 'engagement' => 980, 'engagement_change' => -4.2,
            'top_post' => ['network' => 'Instagram', 'caption' => 'Free refill this week', 'url' => 'https://ig.test/p/1', 'engagement' => 412],
            'best_time' => ['label' => 'Tue 19:00', 'network' => 'Instagram'],
        ],
    ];

    $html = (string) (new WeeklyDigestMail($digest))->toMail($owner)->render();

    expect($html)->toContain('Insight, last week vs the week before')
        ->toContain('Reach: 12,400 (↑ 18.5%)')
        ->toContain('Engagement: 980 (↓ 4.2%)')
        ->toContain('Top post on Instagram')
        ->toContain('Best time to post, from your posts: Tue 19:00 on Instagram')
        ->not->toContain('Capy')
        ->toContain('published');
});

it('leaves the section out when Insight has nothing, and a week with only Insight numbers is not empty', function () {
    $base = ['published' => 0, 'waiting' => 0, 'failed_total' => 0];

    expect(WeeklyDigest::isEmpty($base + ['insight' => null]))->toBeTrue()
        ->and(WeeklyDigest::isEmpty($base + ['insight' => ['reach' => null, 'engagement' => null]]))->toBeTrue()
        ->and(WeeklyDigest::isEmpty($base + ['insight' => ['reach' => 10, 'engagement' => null]]))->toBeFalse();
});
