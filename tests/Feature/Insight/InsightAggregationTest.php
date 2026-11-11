<?php

use App\Models\FollowerSnapshot;
use App\Support\Insight\InsightCollector;
use App\Support\Insight\InsightPeriod;
use App\Support\Workspace;
use Carbon\CarbonImmutable;
use Tests\Fixtures\InsightFixture;

/*
 * What a report says, number by number, from the fixture workspace
 * (tests/Fixtures/InsightFixture.php): exact sums per network, "—" (null)
 * with a footnote wherever a network leaves a number out, never a guess.
 */

beforeEach(function () {
    Workspace::forget();
    $this->travelTo(CarbonImmutable::parse(InsightFixture::NOW, config('app.timezone')));
    InsightFixture::fakeRelay();
    ['owner' => $this->owner, 'accounts' => $this->accounts] = InsightFixture::workspace();
    $this->data = app(InsightCollector::class)->collect(
        $this->owner,
        collect(array_values($this->accounts)),
        InsightPeriod::month(InsightFixture::MONTH),
    );
    $this->channel = fn (string $platform) => collect($this->data['channels'])->firstWhere('platform', $platform);
});

it('sums every Instagram number the network sends', function () {
    $ig = ($this->channel)('instagram');

    expect($ig['current'])->toMatchArray([
        'posts' => 12,
        'reach' => 49800,
        'views' => 70500,
        'likes' => 3792,
        'comments' => 282,
        'shares' => 157,
        'saves' => 501,
        'engagement' => 3792 + 282 + 157 + 501,
    ])
        ->and($ig['current']['rate'])->toEqualWithDelta(4732 / 49800, 1e-9)
        ->and($ig['previous']['posts'])->toBe(9)
        ->and($ig['previous']['reach'])->toBe(29800)
        // Followers from the daily snapshots: 30 Sep → 31 Oct.
        ->and($ig['followers'])->toMatchArray(['start' => 12400, 'end' => 13150, 'delta' => 750])
        ->and($ig['followers_prev_delta'])->toBe(600)
        ->and($ig['available'])->toBeTrue();
});

it('leaves out what Facebook does not send (saves) and says so', function () {
    $fb = ($this->channel)('facebook');

    expect($fb['current']['saves'])->toBeNull()
        ->and($fb['current']['likes'])->toBe(855)
        ->and($fb['current']['engagement'])->toBe(855 + 106 + 82)
        ->and($fb['current']['reach'])->toBe(15100)
        ->and($fb['followers']['delta'])->toBe(85)
        ->and($this->data['footnotes'])->toContain(['key' => 'metric_not_available', 'params' => ['metric' => 'saves', 'network' => 'Facebook']]);
});

it('gives TikTok no engagement rate without reach, and no follower change before its history starts', function () {
    $tt = ($this->channel)('tiktok');

    expect($tt['current']['reach'])->toBeNull()
        ->and($tt['current']['rate'])->toBeNull()
        ->and($tt['current']['views'])->toBe(104400)
        ->and($tt['current']['engagement'])->toBe(8430 + 518 + 971)
        ->and($tt['weekly_reach'])->toBeNull()
        ->and($tt['followers']['delta'])->toBeNull()
        ->and($tt['followers']['since'])->toBe('2026-10-12')
        ->and($this->data['footnotes'])->toContain(['key' => 'metric_not_available', 'params' => ['metric' => 'reach', 'network' => 'TikTok']])
        ->and($this->data['footnotes'])->toContain(['key' => 'followers_no_history', 'params' => ['network' => 'TikTok', 'date' => '2026-10-12']]);
});

it("reads X's nested counts and never asks X for followers", function () {
    $x = ($this->channel)('x');

    expect($x['current'])->toMatchArray([
        'posts' => 10, 'likes' => 354, 'comments' => 61, 'shares' => 28, 'saves' => null,
        'reach' => null, 'views' => null, 'engagement' => 443, 'rate' => null,
    ])
        ->and($x['previous']['posts'])->toBe(0)
        ->and($x['followers']['delta'])->toBeNull()
        ->and($this->data['footnotes'])->toContain(['key' => 'metric_not_available', 'params' => ['metric' => 'followers', 'network' => 'X']]);
});

it('prints a channel whose feed failed as unavailable, not as zero', function () {
    $li = ($this->channel)('linkedin');

    expect($li['available'])->toBeFalse()
        ->and($li['current']['posts'])->toBeNull()
        ->and($li['current']['reach'])->toBeNull()
        ->and($this->data['footnotes'])->toContain(['key' => 'not_available', 'params' => ['network' => 'LinkedIn']]);
});

it('totals only what was reported, and reads the rate over posts that report reach', function () {
    $t = $this->data['totals']['current'];

    expect($t['posts'])->toBe(36)
        ->and($t['reach'])->toBe(49800 + 15100)
        ->and($t['engagement'])->toBe(4732 + 1043 + 9919 + 443)
        // TikTok and X have no reach, so their engagement is not in the rate.
        ->and($t['rate'])->toEqualWithDelta((4732 + 1043) / (49800 + 15100), 1e-9)
        ->and($t['followers_delta'])->toBe(750 + 85)
        ->and($this->data['totals']['previous']['posts'])->toBe(9 + 6 + 4);
});

it('buckets reach by week of the period', function () {
    expect($this->data['weekly_reach'])->toHaveCount(5)
        ->and(array_sum($this->data['weekly_reach']))->toBe(49800 + 15100)
        // 1–7 Oct: IG 4200 + 3900, FB 1800 + 1500.
        ->and($this->data['weekly_reach'][0])->toBe(4200 + 3900 + 1800 + 1500);
});

it('picks the top 3 posts by engagement with an 80-character caption', function () {
    $top = $this->data['top_posts'];

    expect($top)->toHaveCount(3)
        ->and(array_column($top, 'engagement'))->toBe([4680, 1492, 1420])
        ->and($top[0]['network'])->toBe('TikTok')
        ->and($top[0]['thumb_url'])->toStartWith('https://cdn.example.test/')
        ->and(collect($this->data['channels'])->firstWhere('platform', 'instagram')['top'][0]['caption'])
        ->toBe('Giveaway! Tag two friends you would share a pitcher of es kopi with. Winners an…')
        ->and(mb_strlen(collect($this->data['channels'])->firstWhere('platform', 'instagram')['top'][0]['caption']))->toBe(80);
});

it('measures the workflow from our own records', function () {
    $wf = $this->data['workflow']['current'];

    // 3 h, 21 h (both from the client link) and 2 h (from the draft).
    expect($wf['approved'])->toBe(3)
        ->and($wf['avg_approval_seconds'])->toBe((int) round((3 + 21 + 2) * 3600 / 3))
        // +2 min and -5 min are on time; +25 min is not.
        ->and($wf['on_time'])->toBe(2)
        ->and($wf['on_time_known'])->toBe(3)
        ->and($wf['on_time_rate'])->toEqualWithDelta(2 / 3, 1e-9)
        ->and($this->data['workflow']['previous']['approved'])->toBe(1)
        ->and($this->data['workflow']['previous']['avg_approval_seconds'])->toBe(10 * 3600);
});

it('records one follower snapshot a day, overwriting a second reading', function () {
    $account = $this->accounts['instagram'];

    FollowerSnapshot::record($account, 13200);
    FollowerSnapshot::record($account, 13210);

    expect(FollowerSnapshot::query()->where('social_account_id', $account->id)->whereDate('on', '2026-11-05')->pluck('followers')->all())
        ->toBe([13210]);
});
