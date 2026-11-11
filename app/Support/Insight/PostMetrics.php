<?php

namespace App\Support\Insight;

/**
 * The six numbers every Insight screen reads, from whatever a platform calls
 * them. First match wins; null when the platform does not report it.
 */
final class PostMetrics
{
    public const METRICS = [
        'views' => ['views', 'view_count', 'video_views', 'media_views', 'impressions'],
        'likes' => ['likes', 'like_count', 'reactions_total', 'favorites', 'engagement_likes'],
        'comments' => ['comments', 'comment_count', 'replies'],
        'shares' => ['shares', 'share_count', 'quotes'],
        'saves' => ['saved', 'saves'],
        'reach' => ['reach'],
    ];

    /**
     * @param  array<string, mixed>  $raw
     * @return array{views: ?int, likes: ?int, comments: ?int, shares: ?int, saves: ?int, reach: ?int}
     */
    public static function from(array $raw): array
    {
        // X nests its counts one level down.
        $flat = $raw + (array) ($raw['public_metrics'] ?? []);
        $out = [];

        foreach (self::METRICS as $name => $keys) {
            $out[$name] = null;
            foreach ($keys as $k) {
                if (isset($flat[$k]) && is_numeric($flat[$k])) {
                    $out[$name] = (int) $flat[$k];
                    break;
                }
            }
        }

        /** @var array{views: ?int, likes: ?int, comments: ?int, shares: ?int, saves: ?int, reach: ?int} $out */
        return $out;
    }
}
