<?php

namespace App\Support\Media;

use App\Enums\SocialPostStatus;
use App\Models\File;
use App\Models\SocialPost;
use App\Models\User;
use App\Support\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use WeakMap;

/**
 * THE retention calculator — the one the daily job deletes by and the one
 * FileResource shows as auto_delete_at. Rules (founder, 11 Nov 2026):
 *
 * Never automatic: a file marked Keep; one used by a Space, a transfer,
 * an avatar or brand asset (profile cover, appearance, SEO image); any
 * non-post media (PDF, SVG); anything not ready or already in the Trash;
 * a file on a draft or scheduled post.
 *
 * Paid (Pro, Max, trial): 30 days after the last post using it published.
 * A file never on a published post has no clock.
 *
 * Free: right after the post using it published; a failed post keeps it
 * until its 7-day retry window (from its slot) ends; a file on no post at
 * all goes 24 h after upload. With several posts, the latest moment wins.
 */
final class MediaRetention
{
    /** @var WeakMap<File, RetentionVerdict>|null */
    private static ?WeakMap $primed = null;

    public static function for(File $file): RetentionVerdict
    {
        self::$primed ??= new WeakMap;

        if (! isset(self::$primed[$file])) {
            self::prime([$file]);
        }

        return self::$primed[$file];
    }

    /**
     * Work out the verdicts of many files in a few queries, for a listing
     * or a job chunk.
     *
     * @param  iterable<File>  $files
     * @return array<int, RetentionVerdict> keyed by file id
     */
    public static function prime(iterable $files): array
    {
        self::$primed ??= new WeakMap;
        $files = collect($files);
        $out = [];

        foreach ($files->groupBy('user_id') as $ownerId => $group) {
            $owner = User::query()->find($ownerId);
            if ($owner === null) {
                continue;
            }

            // Only files the rules could put a date on need the lookups.
            $ids = $group
                // Kept files too: on Free after a downgrade they have a date.
                ->filter(fn (File $f) => $f->status === File::STATUS_READY && ! $f->trashed() && FileSignature::isPostMedia((string) $f->mime_type))
                ->pluck('id')->all();
            $context = $ids === []
                ? ['tier' => MediaLimits::FREE, 'downgraded_at' => null, 'protected' => [], 'haystack' => '', 'posts' => []]
                : self::context($owner, $ids);

            foreach ($group as $file) {
                /** @var File $file */
                $verdict = self::decide($file, $context);
                self::$primed[$file] = $verdict;
                $out[$file->id] = $verdict;
            }
        }

        return $out;
    }

    /**
     * Pure rule evaluation — every input is in $context.
     *
     * @param  array{tier: string, downgraded_at?: ?CarbonImmutable, protected: array<int, true>, haystack: string, posts: array<int, list<array{status: SocialPostStatus, published_at: ?CarbonImmutable, retry_until: ?CarbonImmutable}>>}  $context
     */
    public static function decide(File $file, array $context): RetentionVerdict
    {
        $kept = $file->kept_at !== null;

        if ($file->status !== File::STATUS_READY || $file->trashed()) {
            return new RetentionVerdict(RetentionVerdict::NOT_READY, null, $kept);
        }
        // Paid: a kept file is never taken.
        if ($kept && $context['tier'] !== MediaLimits::FREE) {
            return new RetentionVerdict(RetentionVerdict::KEPT, null, true);
        }
        if (! FileSignature::isPostMedia((string) $file->mime_type)) {
            return new RetentionVerdict(RetentionVerdict::NOT_POST_MEDIA, null, false);
        }
        if (isset($context['protected'][$file->id]) || ($context['haystack'] !== '' && str_contains($context['haystack'], strtoupper($file->ulid)))) {
            return new RetentionVerdict(RetentionVerdict::PROTECTED, null, false);
        }

        $posts = $context['posts'][$file->id] ?? [];
        $queued = array_filter($posts, fn ($p) => in_array($p['status'], [SocialPostStatus::Draft, SocialPostStatus::Scheduled], true));
        if ($queued !== []) {
            return new RetentionVerdict(RetentionVerdict::QUEUED, null, $kept);
        }

        // Downgrade policy (/terms): after a paid account drops to Free its
        // kept files stay for media.retention.downgrade_keep_days (30) from
        // the downgrade, then go. Kept on Free with no downgrade on record
        // (cannot normally happen: Keep is paid) stays kept.
        if ($kept) {
            $downgraded = $context['downgraded_at'] ?? null;

            return $downgraded === null
                ? new RetentionVerdict(RetentionVerdict::KEPT, null, true)
                : new RetentionVerdict(RetentionVerdict::FREE_KEPT_GRACE, $downgraded->addDays((int) config('media.retention.downgrade_keep_days')), true);
        }

        $published = array_values(array_filter(array_map(fn ($p) => $p['status'] === SocialPostStatus::Published ? $p['published_at'] : null, $posts)));
        $retryEnds = array_values(array_filter(array_map(fn ($p) => $p['status'] === SocialPostStatus::Failed ? $p['retry_until'] : null, $posts)));
        $latest = fn (array $times) => collect($times)->sort()->last();

        if ($context['tier'] !== MediaLimits::FREE) {
            if ($published === []) {
                return new RetentionVerdict(RetentionVerdict::NEVER_PUBLISHED, null, false);
            }

            $due = $latest($published)->addDays((int) config('media.retention.paid_after_publish_days'));
            $retry = $latest($retryEnds);
            if ($retry !== null && $retry->greaterThan($due)) {
                $due = $retry;
            }

            return new RetentionVerdict(RetentionVerdict::PAID_AFTER_PUBLISH, $due, false);
        }

        if ($published === [] && $retryEnds === []) {
            $created = CarbonImmutable::parse($file->created_at ?? now());

            return new RetentionVerdict(RetentionVerdict::FREE_UNATTACHED, $created->addHours((int) config('media.retention.free_unattached_hours')), false);
        }

        $lastPublished = $latest($published);
        $lastRetry = $latest($retryEnds);

        return $lastRetry !== null && ($lastPublished === null || $lastRetry->greaterThan($lastPublished))
            ? new RetentionVerdict(RetentionVerdict::FREE_FAILED_WINDOW, $lastRetry, false)
            : new RetentionVerdict(RetentionVerdict::FREE_PUBLISHED, $lastPublished, false);
    }

    /**
     * Everything decide() needs about one owner's files.
     *
     * @param  list<int>  $ids
     * @return array{tier: string, downgraded_at: ?CarbonImmutable, protected: array<int, true>, haystack: string, posts: array<int, list<array{status: SocialPostStatus, published_at: ?CarbonImmutable, retry_until: ?CarbonImmutable}>>}
     */
    public static function context(User $owner, array $ids): array
    {
        $protected = [];
        foreach ([
            DB::table('space_items')->whereIn('file_id', $ids)->pluck('file_id'),
            DB::table('transfer_files')->whereIn('file_id', $ids)->pluck('file_id'),
        ] as $used) {
            foreach ($used as $id) {
                $protected[(int) $id] = true;
            }
        }

        $posts = [];
        $retryDays = (int) config('media.retention.failed_retry_days');
        SocialPost::query()
            ->join('social_post_files', 'social_post_files.social_post_id', '=', 'social_posts.id')
            ->whereIn('social_post_files.file_id', $ids)
            ->select('social_posts.*', 'social_post_files.file_id as linked_file_id')
            ->get()
            ->each(function (SocialPost $post) use (&$posts, $retryDays) {
                $anchor = $post->scheduled_at ?? CarbonImmutable::parse($post->created_at);
                $posts[(int) $post->getAttribute('linked_file_id')][] = [
                    'status' => $post->status,
                    'published_at' => $post->status === SocialPostStatus::Published ? CarbonImmutable::parse($post->updated_at) : null,
                    'retry_until' => $post->status === SocialPostStatus::Failed ? CarbonImmutable::parse($anchor)->addDays($retryDays) : null,
                ];
            });

        return [
            'tier' => MediaLimits::tier($owner),
            'downgraded_at' => MediaLimits::downgradedAt($owner),
            'protected' => $protected,
            'haystack' => self::brandHaystack($owner),
            'posts' => $posts,
        ];
    }

    /**
     * Avatars and brand assets point at files by URL: everything they hold,
     * upper-cased, so a file ulid inside any of them is found.
     */
    private static function brandHaystack(User $owner): string
    {
        $parts = User::query()->whereIn('id', Workspace::memberIds($owner))->pluck('avatar_url')->all();

        $profile = DB::table('profiles')->where('user_id', $owner->id)->first(['cover_url', 'appearance', 'seo']);
        if ($profile !== null) {
            $parts[] = $profile->cover_url;
            $parts[] = $profile->appearance;
            $parts[] = $profile->seo;
        }

        return strtoupper(implode("\n", array_filter(array_map(fn ($v) => is_string($v) ? $v : null, $parts))));
    }

    /** Forget primed verdicts (tests, long-running workers). */
    public static function flush(): void
    {
        self::$primed = null;
    }
}
