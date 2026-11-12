<?php

use App\Contracts\SocialProvider;
use App\Enums\SocialPostTargetStatus;
use App\Models\FollowerSnapshot;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Insight\InsightCache;
use App\Support\Insight\InsightCollector;
use App\Support\Insight\InsightPeriod;
use App\Support\SocialProviders;
use App\Support\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/*
 * The Insight query cache (5 minutes per workspace + period + channels,
 * retired by new numbers) and the speed it buys: 10 channels × 90 days.
 */

beforeEach(function () {
    Workspace::forget();
    Cache::flush();
    $this->travelTo(CarbonImmutable::parse('2026-11-05 10:00:00', config('app.timezone')));
    $this->feedCalls = 0;
});

/**
 * A Pro workspace with $channels channels, each with a post every
 * $everyHours hours going back $days days, every metric reported. Returns
 * the owner; $this->feedCalls counts the relay's feed pages served.
 */
function bigWorkspace(TestCase $test, int $channels, int $days, int $everyHours = 6): User
{
    $owner = User::factory()->create(['name' => 'Big Agency']);
    Subscription::factory()->for($owner)->create();
    $tz = config('app.timezone');
    $feeds = [];

    foreach (range(1, $channels) as $i) {
        $account = SocialAccount::factory()->for($owner)->create([
            'platform' => ['instagram', 'facebook', 'tiktok', 'linkedin', 'threads'][$i % 5],
            'username' => "brand{$i}", 'provider' => SocialAccount::currentProvider(), 'provider_account_id' => "big_{$i}",
        ]);
        FollowerSnapshot::query()->create(['social_account_id' => $account->id, 'on' => '2026-05-01', 'followers' => 1000 * $i]);

        $start = CarbonImmutable::parse('2026-11-04 20:00', $tz);
        $feeds["big_{$i}"] = array_map(fn (int $n) => [
            'platform_post_id' => "p{$i}_{$n}",
            'caption' => "Post {$n}",
            'posted_at' => $start->subHours($everyHours * $n)->utc()->toIso8601String(),
            'media' => [],
            'provider_post_id' => "op_{$i}_{$n}",
            'metrics' => ['reach' => 100 + $n, 'impressions' => 150 + $n, 'likes' => 10, 'comments' => 2, 'shares' => 1, 'saved' => 1],
        ], range(0, intdiv($days * 24, $everyHours)));
    }

    $provider = Mockery::mock(SocialProvider::class);
    $provider->shouldReceive('feed')->andReturnUsing(function (string $id, ?string $cursor = null, int $limit = 25) use ($feeds, $test) {
        $test->feedCalls++;
        $offset = (int) $cursor;

        return ['data' => array_slice($feeds[$id], $offset, $limit), 'cursor' => $offset + $limit < count($feeds[$id]) ? (string) ($offset + $limit) : null];
    });
    $providers = Mockery::mock(SocialProviders::class);
    $providers->shouldReceive('for')->andReturn($provider);
    app()->instance(SocialProviders::class, $providers);

    return $owner;
}

it('answers 10 channels × 90 days in under 2 s, and again from the cache without the relay', function () {
    $owner = bigWorkspace($this, 10, 185);
    $url = '/api/v1/insight/summary?from=2026-08-07&to=2026-11-04';

    $started = hrtime(true);
    $first = $this->actingAs($owner)->getJson($url)->assertOk();
    $cold = (hrtime(true) - $started) / 1e9;
    $calls = $this->feedCalls;

    $started = hrtime(true);
    $second = $this->actingAs($owner)->getJson($url)->assertOk();
    $warm = (hrtime(true) - $started) / 1e9;

    expect($cold)->toBeLessThan(2.0)
        ->and($warm)->toBeLessThan(0.5)
        ->and($this->feedCalls)->toBe($calls)            // the second answer never asked the relay
        ->and($first->json('data.channels'))->toHaveCount(10)
        ->and($first->json('data.totals.posts'))->toBe(10 * 360)
        ->and($first->json('data.change_pct.posts'))->toEqual(0)   // the whole previous 90 days were read
        ->and($second->json('data'))->toBe($first->json('data'));
});

it('keeps an answer 5 minutes, then reads again', function () {
    $owner = bigWorkspace($this, 2, 30);
    $url = '/api/v1/insight/summary?from=2026-10-06&to=2026-11-04';

    $this->actingAs($owner)->getJson($url)->assertOk();
    $calls = $this->feedCalls;
    $this->travel(4)->minutes();
    $this->actingAs($owner)->getJson($url)->assertOk();
    expect($this->feedCalls)->toBe($calls);

    $this->travel(2)->minutes();
    $this->actingAs($owner)->getJson($url)->assertOk();
    expect($this->feedCalls)->toBeGreaterThan($calls);
});

it('is retired by new numbers: a follower reading, a post going out, an approval', function (string $event) {
    $owner = bigWorkspace($this, 2, 30);
    $account = SocialAccount::query()->where('user_id', $owner->id)->first();
    $post = SocialPost::factory()->for($owner)->create();
    $url = '/api/v1/insight/summary?from=2026-10-06&to=2026-11-04';

    $this->actingAs($owner)->getJson($url)->assertOk();
    $calls = $this->feedCalls;

    match ($event) {
        'followers' => FollowerSnapshot::record($account, 999),
        'published' => SocialPostTarget::query()->create([
            'social_post_id' => $post->id, 'social_account_id' => $account->id, 'status' => SocialPostTargetStatus::Published,
        ]),
        'approval' => $post->forceFill(['approval' => 'approved', 'approval_at' => now()])->save(),
    };

    $this->actingAs($owner)->getJson($url)->assertOk();
    expect($this->feedCalls)->toBeGreaterThan($calls);
})->with(['followers', 'published', 'approval']);

it('keeps workspaces apart: one workspace\'s new numbers leave another\'s answers alone', function () {
    $a = bigWorkspace($this, 1, 30);
    $before = InsightCache::version($a->id);

    $other = User::factory()->create();
    $account = SocialAccount::factory()->for($other)->create(['provider' => SocialAccount::currentProvider()]);
    FollowerSnapshot::record($account, 5);

    expect(InsightCache::version($a->id))->toBe($before)
        ->and(InsightCache::version($other->id))->toBeGreaterThan(0);
});

it('drops the comparison, rather than half-count it, when a busy feed outruns the page limit', function () {
    // A post every 2 hours: 1,080 in 90 days, more than the 1,000 read.
    $owner = bigWorkspace($this, 1, 185, 2);
    $accounts = SocialAccount::query()->where('user_id', $owner->id)->get();

    $data = app(InsightCollector::class)->collect($owner, $accounts, InsightPeriod::range('2026-08-07', '2026-11-04'));
    $channel = $data['channels'][0];

    expect($this->feedCalls)->toBe(InsightCollector::MAX_PAGES)
        ->and($channel['previous']['posts'])->toBeNull()
        ->and($channel['current']['posts'])->toBe(1000)
        ->and($data['footnotes'])->toContain(['key' => 'feed_truncated', 'params' => ['network' => $channel['network'], 'count' => '1000']]);
});
