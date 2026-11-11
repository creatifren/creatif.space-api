<?php

namespace App\Support\Insight;

/**
 * Each network names its numbers differently; these are the six every
 * screen reads, first match wins. Shared by the feed endpoint (Analytics,
 * Grid) and Insight reports so both read a post the same way. Null means
 * the network did not send that number — never zero.
 */
final class FeedMetrics
{
    public const KEYS = [
        'views' => ['views', 'view_count', 'video_views', 'media_views', 'impressions'],
        'likes' => ['likes', 'like_count', 'reactions_total', 'favorites', 'engagement_likes'],
        'comments' => ['comments', 'comment_count', 'replies'],
        'shares' => ['shares', 'share_count', 'quotes'],
        'saves' => ['saved', 'saves'],
        'reach' => ['reach'],
    ];

    /**
     * @param  array<string, mixed>  $raw
     * @return array{views: int|null, likes: int|null, comments: int|null, shares: int|null, saves: int|null, reach: int|null}
     */
    public static function normalise(array $raw): array
    {
        // X nests its counts one level down.
        $flat = $raw + (array) ($raw['public_metrics'] ?? []);
        $out = [];

        foreach (self::KEYS as $name => $keys) {
            $out[$name] = null;
            foreach ($keys as $k) {
                if (isset($flat[$k]) && is_numeric($flat[$k])) {
                    $out[$name] = (int) $flat[$k];
                    break;
                }
            }
        }

        /** @var array{views: int|null, likes: int|null, comments: int|null, shares: int|null, saves: int|null, reach: int|null} $out */
        return $out;
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
