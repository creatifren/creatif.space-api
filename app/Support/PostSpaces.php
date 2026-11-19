<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * v7 "Also add to" on a post: the Galleries / Blogs it goes into when
 * published. One query per request for a list (prime), else one per post.
 */
final class PostSpaces
{
    /** @var array<int, list<array{id: string, type: string, title: string, added: bool}>> */
    private static array $byPost = [];

    /** @param  iterable<\App\Models\SocialPost>  $posts */
    public static function prime(iterable $posts): void
    {
        $ids = [];
        foreach ($posts as $p) {
            if (! array_key_exists($p->id, self::$byPost)) {
                $ids[] = $p->id;
            }
        }
        self::load($ids);
    }

    /** @return list<array{id: string, type: string, title: string, added: bool}> */
    public static function for(int $postId): array
    {
        if (! array_key_exists($postId, self::$byPost)) {
            self::load([$postId]);
        }

        return self::$byPost[$postId] ?? [];
    }

    public static function flush(): void
    {
        self::$byPost = [];
    }

    /** @param  list<int>  $ids */
    private static function load(array $ids): void
    {
        if ($ids === []) {
            return;
        }
        foreach ($ids as $id) {
            self::$byPost[$id] = [];
        }
        $rows = DB::table('social_post_space')->join('spaces', 'spaces.id', '=', 'social_post_space.space_id')
            ->whereIn('social_post_space.social_post_id', $ids)
            ->get(['social_post_space.social_post_id', 'spaces.ulid', 'spaces.type', 'spaces.title', 'social_post_space.added_at']);
        foreach ($rows as $r) {
            self::$byPost[$r->social_post_id][] = ['id' => $r->ulid, 'type' => (string) $r->type, 'title' => (string) $r->title, 'added' => $r->added_at !== null];
        }
    }
}
