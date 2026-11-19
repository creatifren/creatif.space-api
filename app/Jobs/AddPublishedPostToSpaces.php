<?php

namespace App\Jobs;

use App\Models\BlogPost;
use App\Models\File;
use App\Models\SocialPost;
use App\Models\Space;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * v7 "Also add to": a published social post lands in the Galleries and Blogs
 * it was added to — after approval when it needed one, since a held post
 * never publishes. A Gallery gets the post's photos; a Blog gets a post
 * (first line = title, the photos, the caption). Each Space once.
 */
class AddPublishedPostToSpaces implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $postId) {}

    public function handle(): void
    {
        $post = SocialPost::query()->find($this->postId);
        if ($post === null) {
            return;
        }
        $rows = DB::table('social_post_space')->where('social_post_id', $post->id)->whereNull('added_at')->get();
        if ($rows->isEmpty()) {
            return;
        }
        /** @var \Illuminate\Support\Collection<int, File> $files */
        $files = $post->belongsToMany(File::class, 'social_post_files')->where('files.status', File::STATUS_READY)->get();

        foreach ($rows as $row) {
            $space = Space::query()->where('id', $row->space_id)->where('user_id', $post->user_id)->first();
            if ($space !== null) {
                ($space->type ?? 'gallery') === 'blog' ? $this->toBlog($space, $post, $files) : $this->toGallery($space, $post, $files);
            }
            DB::table('social_post_space')->where('social_post_id', $post->id)->where('space_id', $row->space_id)->update(['added_at' => now()]);
        }
    }

    /** @param  \Illuminate\Support\Collection<int, File>  $files */
    private function toGallery(Space $space, SocialPost $post, $files): void
    {
        $order = (int) $space->items()->max('sort_order');
        $title = self::firstLine((string) $post->caption, 80);
        foreach ($files as $file) {
            $space->items()->firstOrCreate(['file_id' => $file->id], [
                'social_post_id' => $post->id,
                'title' => $title,
                'sort_order' => ++$order,
            ]);
        }
    }

    /** @param  \Illuminate\Support\Collection<int, File>  $files */
    private function toBlog(Space $space, SocialPost $post, $files): void
    {
        if (BlogPost::query()->where('space_id', $space->id)->where('social_post_id', $post->id)->exists()) {
            return;
        }
        $caption = trim((string) $post->caption);
        $title = self::firstLine($caption, 120) ?: 'Update';
        $blocks = [
            ...$files->map(fn (File $f) => ['type' => 'photo', 'file_id' => $f->ulid, 'caption' => null])->values()->all(),
            ['type' => 'paragraph', 'text' => $caption],
        ];
        BlogPost::query()->create([
            'space_id' => $space->id,
            'user_id' => $space->user_id,
            'social_post_id' => $post->id,
            'title' => $title,
            'slug' => BlogPost::slugFor($space, $title),
            'cover_file_id' => $files->first()?->id,
            'blocks' => $blocks,
            'status' => 'published',
            'published_at' => now(),
        ]);
    }

    private static function firstLine(string $text, int $max): string
    {
        return Str::limit(trim((string) strtok($text, "\n")), $max, '…');
    }
}
