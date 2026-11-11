<?php

namespace App\Support\Insight;

use App\Casts\UtcDateTime;
use App\Enums\SocialPostApproval;
use App\Enums\SocialPostTargetStatus;
use App\Jobs\RefreshFollowerCount;
use App\Models\FollowerSnapshot;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use App\Support\SocialProviders;
use App\Support\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Everything a report prints, gathered once. Two sources and nothing else:
 *
 *   - the networks' own numbers, read through the relay's feed (posts and
 *     their metrics) and our daily follower snapshots — a number the
 *     network did not send stays null and prints "—" with a footnote;
 *   - our database, for the workflow block (approvals, on-time).
 *
 * Nothing is estimated, interpolated or carried over from another network.
 *
 * The result is plain arrays, so a test can assert on it and a template can
 * print it without reaching back into models.
 */
final class InsightCollector
{
    /** Feed pages read per account at most (25 posts each). */
    public const MAX_PAGES = 20;

    public const PAGE_SIZE = 25;

    /** "On time" = published within this many seconds of the slot. */
    public const ON_TIME_SECONDS = 600;

    public const TOP_POSTS = 3;

    public function __construct(private SocialProviders $providers) {}

    /**
     * @param  Collection<int, SocialAccount>  $accounts
     * @return array<string, mixed>
     */
    public function collect(User $owner, Collection $accounts, InsightPeriod $period): array
    {
        $previous = $period->previous();
        $channels = [];
        $footnotes = [];
        $feeds = [];

        foreach ($accounts as $account) {
            $feed = $this->feed($account, $previous->from);
            $feeds[$account->id] = $feed;
            [$channel, $notes] = $this->channel($account, $feed, $period, $previous);
            $channels[] = $channel;
            array_push($footnotes, ...$notes);
        }

        $current = $this->totals(array_column($channels, 'current'));
        $before = $this->totals(array_column($channels, 'previous'));

        $posts = array_merge(...array_map(fn (array $c): array => $c['top'], $channels));
        usort($posts, fn (array $a, array $b) => $b['engagement'] <=> $a['engagement']);
        $top = array_slice($posts, 0, self::TOP_POSTS);

        return [
            'period' => [
                'from' => $period->from->toDateString(),
                'to' => $period->to->toDateString(),
                'kind' => $period->kind,
            ],
            'previous' => [
                'from' => $previous->from->toDateString(),
                'to' => $previous->to->toDateString(),
            ],
            'channels' => $channels,
            'totals' => [
                'current' => $current + ['followers_delta' => $this->sumOrNull(array_column(array_column($channels, 'followers'), 'delta'))],
                'previous' => $before + ['followers_delta' => $this->sumOrNull(array_column($channels, 'followers_prev_delta'))],
            ],
            'weekly_reach' => $this->weeklyTotal($channels, count($period->weeks())),
            'top_posts' => $top,
            // Every picture the layouts can show (each channel's top posts):
            // thumb_url => the post's media, first image wins.
            'thumbnail_sources' => array_column(
                array_filter($posts, fn (array $p) => $p['thumb_url'] !== null),
                'thumb_candidates',
                'thumb_url',
            ),
            'workflow' => [
                'current' => $this->workflow($owner, $accounts, $period, $feeds),
                'previous' => $this->workflow($owner, $accounts, $previous, $feeds),
            ],
            'footnotes' => array_values(array_unique($footnotes, SORT_REGULAR)),
        ];
    }

    /**
     * The account's feed from now back to $since (the previous period's
     * start), newest first. Null when the network would not give it.
     *
     * @return list<array{posted_at: CarbonImmutable, caption: string, url: string|null, media: list<string>, provider_post_id: string, external_post_id: string|null, metrics: array<string, int|null>}>|null
     */
    private function feed(SocialAccount $account, CarbonImmutable $since): ?array
    {
        $items = [];
        $cursor = null;

        try {
            $provider = $this->providers->for($account);

            for ($page = 0; $page < self::MAX_PAGES; $page++) {
                $result = $provider->feed($account->provider_account_id, $cursor, self::PAGE_SIZE);
                $oldest = null;

                foreach ($result['data'] as $post) {
                    $at = $this->time($post['posted_at'] ?? null);
                    if ($at === null) {
                        continue; // Cannot be placed in a period.
                    }
                    $oldest = $oldest === null || $at->lessThan($oldest) ? $at : $oldest;
                    $items[] = [
                        'posted_at' => $at,
                        'caption' => (string) ($post['caption'] ?? ''),
                        'url' => isset($post['platform_url']) ? (string) $post['platform_url'] : null,
                        'media' => array_values(array_filter(array_map(
                            fn ($m) => is_array($m) ? ($m['thumbnail_url'] ?? $m['url'] ?? null) : (is_string($m) ? $m : null),
                            (array) ($post['media'] ?? []),
                        ))),
                        'provider_post_id' => (string) ($post['provider_post_id'] ?? ''),
                        'external_post_id' => isset($post['external_post_id']) ? (string) $post['external_post_id'] : null,
                        'metrics' => PostMetrics::from((array) ($post['metrics'] ?? [])),
                    ];
                }

                $cursor = $result['cursor'];
                if ($cursor === null || ($oldest !== null && $oldest->lessThan($since))) {
                    break;
                }
            }
        } catch (Throwable $e) {
            Log::warning('insight.feed_failed', ['account' => $account->ulid, 'error' => $e->getMessage()]);

            return null;
        }

        return $items;
    }

    /**
     * @param  list<array<string, mixed>>|null  $feed
     * @return array{0: array<string, mixed>, 1: list<array{key: string, params: array<string, string>}>}
     */
    private function channel(SocialAccount $account, ?array $feed, InsightPeriod $period, InsightPeriod $previous): array
    {
        $network = InsightStrings::network($account->platform->value);
        $notes = [];

        $inPeriod = $feed === null ? null : array_values(array_filter($feed, fn ($p) => $period->contains($p['posted_at'])));
        $inPrevious = $feed === null ? null : array_values(array_filter($feed, fn ($p) => $previous->contains($p['posted_at'])));

        $current = $this->metrics($inPeriod);
        $before = $this->metrics($inPrevious);

        if ($feed === null) {
            $notes[] = ['key' => 'not_available', 'params' => ['network' => $network]];
        } elseif ($current['posts'] > 0) {
            foreach (['reach', 'views', 'likes', 'comments', 'shares', 'saves'] as $metric) {
                if ($current[$metric] === null) {
                    $notes[] = ['key' => 'metric_not_available', 'params' => ['metric' => $metric === 'views' ? 'impressions' : $metric, 'network' => $network]];
                }
            }
        }

        $followers = $this->followers($account, $period);
        $followersBefore = $this->followers($account, $previous);

        if ($followers['delta'] === null) {
            $notes[] = in_array($account->platform, RefreshFollowerCount::SKIPPED, true)
                ? ['key' => 'metric_not_available', 'params' => ['metric' => 'followers', 'network' => $network]]
                : ($followers['since'] !== null
                    ? ['key' => 'followers_no_history', 'params' => ['network' => $network, 'date' => $followers['since']]]
                    : ['key' => 'followers_no_history_none', 'params' => ['network' => $network]]);
        }

        $top = collect($inPeriod ?? [])
            ->map(fn (array $p) => [
                'platform' => $account->platform->value,
                'network' => $network,
                'username' => $account->username,
                'posted_at' => $p['posted_at']->setTimezone(config('app.timezone'))->toIso8601String(),
                'caption' => InsightFormat::caption($p['caption']),
                'url' => $p['url'],
                'thumb_url' => $p['media'][0] ?? null,
                // The first item may be a video: up to four to find an image in.
                'thumb_candidates' => array_slice($p['media'], 0, 4),
                'engagement' => self::engagement($p['metrics']),
                'reach' => $p['metrics']['reach'],
                'views' => $p['metrics']['views'],
            ])
            ->filter(fn (array $p) => $p['engagement'] !== null)
            ->sortByDesc('engagement')
            ->take(self::TOP_POSTS)
            ->values()
            ->all();

        $weekly = $inPeriod === null || $current['reach'] === null
            ? null
            : array_map(
                fn (array $w) => (int) collect($inPeriod)
                    ->filter(fn ($p) => $p['posted_at']->greaterThanOrEqualTo($w[0]) && $p['posted_at']->lessThanOrEqualTo($w[1]))
                    ->sum(fn ($p) => $p['metrics']['reach'] ?? 0),
                $period->weeks(),
            );

        return [[
            'id' => $account->ulid,
            'platform' => $account->platform->value,
            'network' => $network,
            'username' => $account->username !== null ? '@'.ltrim($account->username, '@') : null,
            'available' => $feed !== null,
            'current' => $current,
            'previous' => $before,
            'followers' => $followers,
            'followers_prev_delta' => $followersBefore['delta'],
            'top' => $top,
            'weekly_reach' => $weekly,
            'notes' => $notes,
        ], $notes];
    }

    /**
     * @param  list<array<string, mixed>>|null  $posts
     * @return array{posts: int|null, reach: int|null, views: int|null, likes: int|null, comments: int|null, shares: int|null, saves: int|null, engagement: int|null, rate: float|null, rate_engagement: int|null, rate_reach: int|null}
     */
    private function metrics(?array $posts): array
    {
        $out = ['posts' => null, 'reach' => null, 'views' => null, 'likes' => null, 'comments' => null,
            'shares' => null, 'saves' => null, 'engagement' => null, 'rate' => null,
            'rate_engagement' => null, 'rate_reach' => null];

        if ($posts === null) {
            return $out;
        }

        $out['posts'] = count($posts);

        foreach (['reach', 'views', 'likes', 'comments', 'shares', 'saves'] as $metric) {
            $out[$metric] = $this->sumOrNull(array_map(fn ($p) => $p['metrics'][$metric], $posts));
        }

        $out['engagement'] = $this->sumOrNull(array_map(fn ($p) => self::engagement($p['metrics']), $posts));

        // The rate is read only over posts that report both numbers, so a
        // network that hides reach on some posts cannot inflate it.
        $both = array_filter($posts, fn ($p) => $p['metrics']['reach'] !== null && self::engagement($p['metrics']) !== null);
        if ($both !== []) {
            $out['rate_engagement'] = (int) array_sum(array_map(fn ($p) => self::engagement($p['metrics']), $both));
            $out['rate_reach'] = (int) array_sum(array_map(fn ($p) => $p['metrics']['reach'], $both));
            $out['rate'] = $out['rate_reach'] > 0 ? $out['rate_engagement'] / $out['rate_reach'] : null;
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, int|float|null>
     */
    private function totals(array $rows): array
    {
        $out = [];
        foreach (['posts', 'reach', 'views', 'likes', 'comments', 'shares', 'saves', 'engagement', 'rate_engagement', 'rate_reach'] as $metric) {
            $out[$metric] = $this->sumOrNull(array_column($rows, $metric));
        }
        $out['rate'] = ($out['rate_reach'] ?? 0) > 0 ? $out['rate_engagement'] / $out['rate_reach'] : null;

        return $out;
    }

    /**
     * Start and end from our daily snapshots: the last reading on or before
     * the period's first day, and the last on or before its last day (but
     * not before the start reading).
     *
     * @return array{start: int|null, end: int|null, delta: int|null, since: string|null}
     */
    private function followers(SocialAccount $account, InsightPeriod $period): array
    {
        $none = ['start' => null, 'end' => null, 'delta' => null, 'since' => null];

        if (in_array($account->platform, RefreshFollowerCount::SKIPPED, true)) {
            return $none;
        }

        $snapshots = FollowerSnapshot::query()->where('social_account_id', $account->id);
        $first = (clone $snapshots)->orderBy('on')->first();

        // whereDate: a date column reads back with a time on some drivers.
        $start = (clone $snapshots)->whereDate('on', '<=', $period->from->toDateString())->orderByDesc('on')->first();
        $end = (clone $snapshots)->whereDate('on', '<=', $period->to->toDateString())->orderByDesc('on')->first();

        $since = $first?->on->toDateString();

        if ($start === null || $end === null || $end->on->lessThanOrEqualTo($start->on)) {
            return ['start' => $start?->followers, 'end' => $end?->followers, 'delta' => null, 'since' => $since];
        }

        return [
            'start' => $start->followers,
            'end' => $end->followers,
            'delta' => $end->followers - $start->followers,
            'since' => $since,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $channels
     * @return list<int>|null
     */
    private function weeklyTotal(array $channels, int $weeks): ?array
    {
        $rows = array_values(array_filter(array_column($channels, 'weekly_reach'), fn ($w) => $w !== null));

        if ($rows === []) {
            return null;
        }

        return array_map(fn (int $i) => (int) array_sum(array_column($rows, $i)), range(0, $weeks - 1));
    }

    /**
     * Approvals and punctuality, from our own records, for posts that went
     * (or were meant to go) to the report's channels.
     *
     * @param  Collection<int, SocialAccount>  $accounts
     * @param  array<int, list<array<string, mixed>>|null>  $feeds
     * @return array{approved: int, avg_approval_seconds: int|null, on_time: int, on_time_known: int, on_time_rate: float|null}
     */
    private function workflow(User $owner, Collection $accounts, InsightPeriod $period, array $feeds): array
    {
        $accountIds = $accounts->pluck('id')->all();
        $posts = SocialPost::query()
            ->whereIn('user_id', Workspace::memberIds($owner))
            ->whereHas('targets', fn ($q) => $q->whereIn('social_account_id', $accountIds));

        $approved = (clone $posts)
            ->where('approval', SocialPostApproval::Approved->value)
            ->whereBetween('approval_at', [$period->from, $period->to])
            ->get(['id', 'created_at', 'approval_at']);

        $requested = $approved->isEmpty() ? collect() : DB::table('social_approval_link_post')
            ->join('social_approval_links', 'social_approval_links.id', '=', 'social_approval_link_post.social_approval_link_id')
            ->whereIn('social_approval_link_post.social_post_id', $approved->pluck('id'))
            ->groupBy('social_approval_link_post.social_post_id')
            ->selectRaw('social_approval_link_post.social_post_id as post_id, min(social_approval_links.created_at) as requested_at')
            ->pluck('requested_at', 'post_id');

        $durations = $approved
            ->map(function (SocialPost $post) use ($requested) {
                $asked = $requested->get($post->id);
                $from = $asked !== null ? CarbonImmutable::parse((string) $asked, config('app.timezone')) : $post->created_at;

                return $from !== null && $post->approval_at !== null
                    ? (int) $from->diffInSeconds($post->approval_at, false)
                    : null;
            })
            ->filter(fn ($s) => $s !== null && $s >= 0);

        // On time: scheduled in the period, published, and the network's
        // own publish time is within ON_TIME_SECONDS of the slot.
        $scheduled = (clone $posts)
            ->whereNotNull('scheduled_at')
            ->whereBetween('scheduled_at', [UtcDateTime::bind($period->from), UtcDateTime::bind($period->to)])
            ->with(['targets' => fn ($q) => $q->whereIn('social_account_id', $accountIds)
                ->where('status', SocialPostTargetStatus::Published->value)])
            ->get();

        $known = 0;
        $onTime = 0;

        foreach ($scheduled as $post) {
            foreach ($post->targets as $target) {
                $item = collect($feeds[$target->social_account_id] ?? [])
                    ->first(fn ($p) => ($p['external_post_id'] !== null && $p['external_post_id'] === $post->ulid)
                        || ($post->provider_post_id !== null && $p['provider_post_id'] === $post->provider_post_id));

                if ($item === null || $post->scheduled_at === null) {
                    continue;
                }

                $known++;
                if (abs($item['posted_at']->getTimestamp() - $post->scheduled_at->getTimestamp()) <= self::ON_TIME_SECONDS) {
                    $onTime++;
                }
            }
        }

        return [
            'approved' => $approved->count(),
            'avg_approval_seconds' => $durations->isEmpty() ? null : (int) round($durations->avg()),
            'on_time' => $onTime,
            'on_time_known' => $known,
            'on_time_rate' => $known > 0 ? $onTime / $known : null,
        ];
    }

    private function time(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<int, int|float|null>  $values
     */
    private function sumOrNull(array $values): int|float|null
    {
        $present = array_filter($values, fn ($v) => $v !== null);

        return $present === [] ? null : array_sum($present);
    }

    /**
     * Likes + comments + shares + saves, over the ones the network sent.
     * Null when it sent none of them.
     *
     * @param  array<string, int|null>  $m
     */
    public static function engagement(array $m): ?int
    {
        $parts = array_filter(
            [$m['likes'] ?? null, $m['comments'] ?? null, $m['shares'] ?? null, $m['saves'] ?? null],
            fn ($v) => $v !== null,
        );

        return $parts === [] ? null : (int) array_sum($parts);
    }
}
