<?php

namespace App\Support\Media;

use App\Models\SocialPost;
use App\Support\PlanScope;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * The small copies (480 px thumb, 1080 px feed, video poster) of a post's
 * media for the dashboard: Posts Grid / List / Calendar / Today, the post
 * page and Compose drew every original (up to 10 MB each) in a 42 px or
 * 120 px slot. Same pictures and shape as the client approval page
 * (PlanScope::media → variants), one list per post, aligned with `media`.
 *
 * A list endpoint primes the whole page in one files query; a single post
 * falls back to its own query. Kept on the request, so nothing outlives it.
 */
final class PostMediaVariants
{
    private const KEY = 'post_media_variants';

    /** @param  Collection<int, SocialPost>  $posts */
    public static function prime(Request $request, Collection $posts): void
    {
        if ($posts->isEmpty()) {
            return;
        }
        $map = (array) $request->attributes->get(self::KEY, []);
        foreach (PlanScope::media($posts) as $id => $items) {
            $map[$id] = self::shape($items);
        }
        $request->attributes->set(self::KEY, $map);
    }

    /**
     * One entry per media item: {thumb, feed, poster} (each {webp, jpeg,
     * width, height} or null), or null for media outside the library.
     *
     * @return list<array<string, mixed>|null>
     */
    public static function for(Request $request, SocialPost $post): array
    {
        if (($post->media ?? []) === []) {
            return [];
        }
        $map = (array) $request->attributes->get(self::KEY, []);
        if (! array_key_exists($post->id, $map)) {
            self::prime($request, collect([$post]));
            $map = (array) $request->attributes->get(self::KEY, []);
        }

        return $map[$post->id] ?? [];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>|null>
     */
    private static function shape(array $items): array
    {
        return array_map(function (array $m): ?array {
            $v = $m['variants'] ?? [];
            if (($v['thumb'] ?? null) === null && ($v['feed'] ?? null) === null && ($v['poster'] ?? null) === null) {
                return null;
            }

            return ['thumb' => $v['thumb'] ?? null, 'feed' => $v['feed'] ?? null, 'poster' => $v['poster'] ?? null];
        }, $items);
    }
}
