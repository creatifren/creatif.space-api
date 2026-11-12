<?php

namespace App\Support\Insight;

/**
 * Why an Insight metric is empty, when an official source says so.
 *
 * Some networks hold back a metric until the account is big enough (Meta's
 * "100 followers" rule for audience data, for example). When the account's
 * follower count is known and under the number an official platform page
 * states, Insight returns a `below_threshold` note for that metric instead of
 * leaving the reader to guess.
 *
 * Only thresholds an official page states as a number belong here, with that
 * page next to each one so it can be checked again. The app keeps the same
 * table (analytics/thresholds.ts); change both together.
 */
final class InsightThresholds
{
    /** @var list<array{platform: string, metrics: list<string>, min: int, source: string}> */
    public const RULES = [
        // "Some metrics are not available on Instagram accounts with fewer
        // than 100 followers." — follower_demographics, online_followers …
        [
            'platform' => 'instagram',
            'metrics' => ['audience'],
            'min' => 100,
            'source' => 'https://developers.facebook.com/docs/instagram-platform/insights/',
        ],
        // "A Threads profile must have at least 100 followers in order to
        // fetch values for the follower_demographics metric."
        [
            'platform' => 'threads',
            'metrics' => ['audience'],
            'min' => 100,
            'source' => 'https://developers.facebook.com/docs/threads/insights/',
        ],
        // "Demographic metrics, such as age, gender, and location, are only
        // returned if there is data for 100 or more people."
        [
            'platform' => 'facebook',
            'metrics' => ['audience'],
            'min' => 100,
            'source' => 'https://developers.facebook.com/docs/graph-api/reference/v24.0/insights',
        ],
    ];

    /**
     * The `below_threshold` notes for one channel: one per metric an official
     * rule withholds at this follower count. None when the count is unknown.
     *
     * @return list<array{key: string, params: array<string, string>, metric: string, reason: string, threshold: int, source_url: string}>
     */
    public static function notes(string $platform, ?int $followers, string $network): array
    {
        if ($followers === null) {
            return [];
        }

        $notes = [];
        foreach (self::RULES as $rule) {
            if ($rule['platform'] !== $platform || $followers >= $rule['min']) {
                continue;
            }
            foreach ($rule['metrics'] as $metric) {
                $notes[] = [
                    'key' => 'below_threshold',
                    'params' => ['metric' => $metric, 'network' => $network, 'threshold' => (string) $rule['min']],
                    'metric' => $metric,
                    'reason' => 'below_threshold',
                    'threshold' => $rule['min'],
                    'source_url' => $rule['source'],
                ];
            }
        }

        return $notes;
    }
}
