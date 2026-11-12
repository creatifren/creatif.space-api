<?php

namespace App\Support\Insight;

use Carbon\CarbonImmutable;

/**
 * Best time to post, from the channel's own posts — never a benchmark or
 * an industry average. Each post with engagement reported counts once, in
 * the weekday + hour it went out (app timezone); a slot's score is the
 * average engagement of its posts. The top SLOTS slots are returned, best
 * first. Under MIN_POSTS posts there is too little to say anything:
 * status `not_enough_data`, no slots.
 */
final class InsightBestTimes
{
    public const MIN_POSTS = 10;

    public const SLOTS = 3;

    private const DAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

    /**
     * @param  list<array{posted_at: CarbonImmutable, metrics: array<string, int|null>}>|null  $posts
     * @return array{status: string, posts_analyzed: int, min_posts: int, slots: list<array{weekday: string, weekday_index: int, hour: int, label: string, posts: int, avg_engagement: float}>}
     */
    public static function of(?array $posts): array
    {
        $tz = (string) config('app.timezone');
        $buckets = [];
        $counted = 0;

        foreach ($posts ?? [] as $p) {
            $engagement = InsightCollector::engagement($p['metrics']);
            if ($engagement === null) {
                continue;
            }
            $counted++;
            $at = $p['posted_at']->setTimezone($tz);
            $key = $at->dayOfWeekIso.'-'.$at->hour;
            $buckets[$key] ??= ['day' => $at->dayOfWeekIso, 'hour' => $at->hour, 'posts' => 0, 'sum' => 0];
            $buckets[$key]['posts']++;
            $buckets[$key]['sum'] += $engagement;
        }

        if ($counted < self::MIN_POSTS) {
            return ['status' => 'not_enough_data', 'posts_analyzed' => $counted, 'min_posts' => self::MIN_POSTS, 'slots' => []];
        }

        $slots = array_map(fn (array $b) => [
            'weekday' => self::DAYS[$b['day'] - 1],
            'weekday_index' => $b['day'],
            'hour' => $b['hour'],
            'label' => substr(self::DAYS[$b['day'] - 1], 0, 3).' '.str_pad((string) $b['hour'], 2, '0', STR_PAD_LEFT).':00',
            'posts' => $b['posts'],
            'avg_engagement' => round($b['sum'] / $b['posts'], 1),
        ], array_values($buckets));

        // Best average first; more posts behind it breaks a tie, then the earlier slot.
        usort($slots, fn ($a, $b) => [$b['avg_engagement'], $b['posts'], $a['weekday_index'], $a['hour']]
            <=> [$a['avg_engagement'], $a['posts'], $b['weekday_index'], $b['hour']]);

        return ['status' => 'ok', 'posts_analyzed' => $counted, 'min_posts' => self::MIN_POSTS, 'slots' => array_slice($slots, 0, self::SLOTS)];
    }
}
