<?php

namespace App\Support\Media;

use App\Models\File;
use App\Models\SocialPost;
use App\Support\Workspace;
use Illuminate\Support\Facades\DB;

/**
 * Which library files a post carries.
 *
 * A post stores its media as URLs (signed R2 URLs for library files), so
 * the file behind an entry is read back from the object key in the URL:
 * u/<owner ulid>/<file ulid>.<ext>. social_post_files keeps the result,
 * re-synced whenever a post's media changes (SocialPost::saved), so the
 * retention job and the Keep quota can query it.
 */
final class MediaLinks
{
    private const KEY = '~/u/([0-9A-Za-z]{26})/([0-9A-Za-z]{26})(?:\.[A-Za-z0-9]{1,8})?$~';

    /** The file ulid a media URL points at, or null for any other URL. */
    public static function fileUlid(string $url): ?string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        return preg_match(self::KEY, rawurldecode($path), $m) === 1 ? strtoupper($m[2]) : null;
    }

    /** The library file a media URL points at, if it still exists. */
    public static function fileFor(string $url): ?File
    {
        $ulid = self::fileUlid($url);

        return $ulid === null ? null : File::query()->where('ulid', $ulid)->first();
    }

    /**
     * File ulids in a post's media (url and video cover).
     *
     * @param  array<int, mixed>|null  $media
     * @return list<string>
     */
    public static function ulidsIn(?array $media): array
    {
        $ulids = [];
        foreach ($media ?? [] as $entry) {
            foreach (['url', 'thumbnail_url'] as $key) {
                $url = is_array($entry) ? ($entry[$key] ?? null) : null;
                $ulid = is_string($url) ? self::fileUlid($url) : null;
                if ($ulid !== null) {
                    $ulids[$ulid] = true;
                }
            }
        }

        return array_keys($ulids);
    }

    /** Make social_post_files match the post's media. Only the workspace's own files link. */
    public static function sync(SocialPost $post): void
    {
        $ulids = self::ulidsIn($post->media);
        $ownerId = $post->user === null ? $post->user_id : Workspace::owner($post->user)->id;

        $ids = $ulids === [] ? [] : File::withTrashed()
            ->where('user_id', $ownerId)
            ->whereIn('ulid', $ulids)
            ->pluck('id')
            ->all();

        DB::transaction(function () use ($post, $ids) {
            DB::table('social_post_files')
                ->where('social_post_id', $post->id)
                ->when($ids !== [], fn ($q) => $q->whereNotIn('file_id', $ids))
                ->delete();

            $have = DB::table('social_post_files')->where('social_post_id', $post->id)->pluck('file_id')->all();
            $now = now();
            $rows = array_map(
                fn (int $id) => ['social_post_id' => $post->id, 'file_id' => $id, 'created_at' => $now, 'updated_at' => $now],
                array_values(array_diff($ids, $have)),
            );

            if ($rows !== []) {
                DB::table('social_post_files')->insert($rows);
            }
        });
    }

    /** Link every existing post with media. Chunked; safe to re-run. */
    public static function backfill(): int
    {
        $n = 0;
        SocialPost::query()
            ->whereNotNull('media')
            ->with('user')
            ->chunkById(200, function ($posts) use (&$n) {
                foreach ($posts as $post) {
                    self::sync($post);
                    $n++;
                }
            });

        return $n;
    }
}
