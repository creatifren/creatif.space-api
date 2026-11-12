<?php

namespace App\Support\Insight;

use Carbon\CarbonImmutable;

/**
 * The numbers of an Insight report as data, for GET /insight/summary (the
 * MCP tool get_insight_summary): the same InsightCollector result the PDF
 * prints, with raw numbers instead of formatted strings. Null is "the
 * network did not report it" — never zero — and `notes` says why, in the
 * same words as the report's footnotes.
 */
final class InsightSummary
{
    /**
     * @param  array<string, mixed>  $data  InsightCollector::collect()
     * @return array<string, mixed>
     */
    public static function present(array $data): array
    {
        $kind = $data['period']['kind'];
        $period = InsightPeriod::fromDates(CarbonImmutable::parse($data['period']['from']), CarbonImmutable::parse($data['period']['to']), $kind);
        $previous = InsightPeriod::fromDates(CarbonImmutable::parse($data['previous']['from']), CarbonImmutable::parse($data['previous']['to']), $kind);

        $cur = $data['totals']['current'];
        $prev = $data['totals']['previous'];
        $wf = $data['workflow']['current'];
        $wfPrev = $data['workflow']['previous'];
        $weeks = $period->weeks();

        return [
            'period' => ['from' => $data['period']['from'], 'to' => $data['period']['to'], 'kind' => $kind, 'label' => $period->label('en')],
            'compared_with' => ['from' => $data['previous']['from'], 'to' => $data['previous']['to'], 'label' => $previous->label('en')],
            'totals' => self::numbers($cur) + ['followers_change' => $cur['followers_delta']],
            'totals_previous' => self::numbers($prev) + ['followers_change' => $prev['followers_delta']],
            'change_pct' => self::changes($cur, $prev),
            'weekly_reach' => $data['weekly_reach'] === null ? null : array_map(
                fn (int $reach, int $i) => ['week_start' => $weeks[$i][0]->toDateString(), 'reach' => $reach],
                $data['weekly_reach'],
                array_keys($data['weekly_reach']),
            ),
            'channels' => array_map(fn (array $c) => [
                'id' => $c['id'],
                'platform' => $c['platform'],
                'network' => $c['network'],
                'username' => $c['username'],
                'available' => $c['available'],
                ...self::numbers($c['current']),
                'followers' => [
                    'start' => $c['followers']['start'],
                    'end' => $c['followers']['end'],
                    'change' => $c['followers']['delta'],
                    'previous_change' => $c['followers_prev_delta'],
                    'history_since' => $c['followers']['since'],
                ],
                'previous' => self::numbers($c['previous']),
                'change_pct' => self::changes($c['current'], $c['previous']),
                // Metrics the network holds back below an official follower count.
                'notes' => array_values(array_map(fn (array $n) => [
                    'metric' => $n['metric'],
                    'reason' => $n['reason'],
                    'threshold' => $n['threshold'],
                    'source_url' => $n['source_url'],
                    'text' => InsightStrings::get('en', 'below_threshold', [
                        ...$n['params'],
                        'metric' => InsightStrings::get('en', $n['metric']),
                    ]),
                ], array_filter($c['notes'], fn (array $n) => ($n['reason'] ?? null) === 'below_threshold'))),
            ], $data['channels']),
            'top_posts' => array_map(fn (array $p) => [
                'network' => $p['network'],
                'username' => $p['username'],
                'posted_at' => $p['posted_at'],
                'caption' => $p['caption'],
                'url' => $p['url'],
                'engagement' => $p['engagement'],
                'reach' => $p['reach'],
                'impressions' => $p['views'],
            ], $data['top_posts']),
            'workflow' => [
                ...self::workflow($wf),
                'previous' => self::workflow($wfPrev),
                'change_pct' => self::workflowChanges(self::workflow($wf), self::workflow($wfPrev)),
            ],
            'notes' => array_values(array_unique(array_map(function (array $note): string {
                $params = $note['params'];
                if (isset($params['metric'])) {
                    $params['metric'] = InsightStrings::get('en', $params['metric']);
                }
                if (isset($params['date'])) {
                    $params['date'] = CarbonImmutable::parse($params['date'])->format('j M Y');
                }

                return InsightStrings::get('en', $note['key'], $params);
            }, $data['footnotes']))),
            'definitions' => [
                InsightStrings::get('en', 'source_note'),
                InsightStrings::get('en', 'engagement_note'),
                InsightStrings::get('en', 'followers_note'),
                InsightStrings::get('en', 'approval_note'),
                InsightStrings::get('en', 'on_time_note'),
                InsightStrings::get('en', 'comparison_note'),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $m
     * @return array{posts: int|null, reach: int|null, impressions: int|null, engagement: int|null, engagement_rate: float|null, likes: int|null, comments: int|null, shares: int|null, saves: int|null}
     */
    private static function numbers(array $m): array
    {
        return [
            'posts' => $m['posts'],
            'reach' => $m['reach'],
            'impressions' => $m['views'],
            'engagement' => $m['engagement'],
            'engagement_rate' => $m['rate'] === null ? null : round($m['rate'], 4),
            'likes' => $m['likes'],
            'comments' => $m['comments'],
            'shares' => $m['shares'],
            'saves' => $m['saves'],
        ];
    }

    /**
     * Percent change against the previous period, by the report's rule:
     * only when the previous period published something and both numbers
     * exist (and the previous one is above zero).
     *
     * @param  array<string, mixed>  $cur
     * @param  array<string, mixed>  $prev
     * @return array<string, float|null>
     */
    private static function changes(array $cur, array $prev): array
    {
        $comparable = ($prev['posts'] ?? 0) > 0;
        $pct = fn ($a, $b) => $comparable && $a !== null && $b !== null && $b > 0
            ? round(($a - $b) / $b * 100, 1)
            : null;

        return [
            'posts' => $pct($cur['posts'], $prev['posts']),
            'reach' => $pct($cur['reach'], $prev['reach']),
            'impressions' => $pct($cur['views'], $prev['views']),
            'engagement' => $pct($cur['engagement'], $prev['engagement']),
            'engagement_rate' => $pct($cur['rate'], $prev['rate']),
            'likes' => $pct($cur['likes'], $prev['likes']),
            'comments' => $pct($cur['comments'], $prev['comments']),
            'shares' => $pct($cur['shares'], $prev['shares']),
            'saves' => $pct($cur['saves'], $prev['saves']),
        ];
    }

    /**
     * The workflow against the previous period: only when both have a number
     * and the previous one is above zero.
     *
     * @param  array<string, int|float|null>  $cur
     * @param  array<string, int|float|null>  $prev
     * @return array{posts_approved: float|null, avg_approval_hours: float|null, on_time_rate: float|null}
     */
    private static function workflowChanges(array $cur, array $prev): array
    {
        $pct = fn ($a, $b) => $a !== null && $b !== null && $b > 0 ? round(($a - $b) / $b * 100, 1) : null;

        return [
            'posts_approved' => $pct($cur['posts_approved'], $prev['posts_approved']),
            'avg_approval_hours' => $pct($cur['avg_approval_hours'], $prev['avg_approval_hours']),
            'on_time_rate' => $pct($cur['on_time_rate'], $prev['on_time_rate']),
        ];
    }

    /**
     * @param  array<string, mixed>  $wf
     * @return array{posts_approved: int, avg_approval_hours: float|null, on_time_rate: float|null, on_time: int, on_time_known: int}
     */
    private static function workflow(array $wf): array
    {
        return [
            'posts_approved' => $wf['approved'],
            'avg_approval_hours' => $wf['avg_approval_seconds'] === null ? null : round($wf['avg_approval_seconds'] / 3600, 1),
            'on_time_rate' => $wf['on_time_rate'] === null ? null : round($wf['on_time_rate'], 4),
            'on_time' => $wf['on_time'],
            'on_time_known' => $wf['on_time_known'],
        ];
    }
}
