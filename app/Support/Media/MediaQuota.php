<?php

namespace App\Support\Media;

use App\Enums\SocialPostStatus;
use App\Exceptions\MediaRefused;
use App\Models\File;
use App\Models\User;
use App\Support\PlanQuota;
use App\Support\Workspace;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Paid: the Keep quota (Pro 25 GB, Max 50 GB, trial = Pro) — files marked
 * Keep plus media on draft/scheduled posts, each file counted once.
 * Free: the 1 GB total cap on everything stored.
 */
final class MediaQuota
{
    /** Bytes counted against the Keep quota. */
    public static function keepUsed(User $user): int
    {
        $owner = Workspace::owner($user);

        return (int) File::query()
            ->where('user_id', $owner->id)
            ->where('status', File::STATUS_READY)
            ->where(fn ($q) => $q->whereNotNull('kept_at')->orWhereIn('id', self::queuedFileIds()))
            ->sum('size_bytes');
    }

    /**
     * The usage block: paid {keep_used_bytes, keep_quota_bytes}, Free
     * {used_bytes, quota_bytes}.
     *
     * @return array<string, int|string>
     */
    public static function usage(User $user): array
    {
        if (MediaLimits::paid($user)) {
            return [
                'tier' => MediaLimits::tier($user),
                'keep_used_bytes' => self::keepUsed($user),
                'keep_quota_bytes' => MediaLimits::keepQuota($user),
            ];
        }

        return [
            'tier' => MediaLimits::FREE,
            'used_bytes' => PlanQuota::storageUsed(Workspace::owner($user)),
            'quota_bytes' => (int) MediaLimits::totalCap($user),
        ];
    }

    /**
     * Room for $bytes more, or refuse: Free against the total cap, paid
     * against the Keep quota (an upload is headed for a post or a Keep).
     *
     * @throws MediaRefused
     */
    public static function assertUploadFits(User $user, int $bytes): void
    {
        $owner = Workspace::owner($user);

        if (! MediaLimits::paid($owner)) {
            if (PlanQuota::storageUsed($owner) + $bytes > (int) MediaLimits::totalCap($owner)) {
                MediaUploadGuard::hit($owner, 'media_quota');

                throw MediaRefused::quota('media_quota', 'upgrade_pro', 'Your media storage is full (1 GB on Free). Upgrade to Pro for more.');
            }

            return;
        }

        if (self::keepUsed($owner) + $bytes > MediaLimits::keepQuota($owner)) {
            MediaUploadGuard::hit($owner, 'keep_quota');

            throw self::keepFull($owner);
        }
    }

    /**
     * Mark a file Keep, if the quota has room for it. A file already counted
     * (on a queued post) adds nothing. Idempotent.
     *
     * @throws MediaRefused
     */
    public static function keep(User $user, File $file): void
    {
        if ($file->kept_at !== null) {
            return;
        }

        $owner = Workspace::owner($user);

        DB::transaction(function () use ($owner, $file) {
            User::query()->whereKey($owner->id)->lockForUpdate()->first();

            $counted = DB::table('social_post_files')
                ->where('file_id', $file->id)
                ->whereIn('social_post_id', self::queuedPostIds())
                ->exists();
            $adds = $counted ? 0 : (int) $file->size_bytes;

            if (! MediaLimits::paid($owner) || self::keepUsed($owner) + $adds > MediaLimits::keepQuota($owner)) {
                throw self::keepFull($owner);
            }

            $file->forceFill(['kept_at' => now()])->save();
        });
    }

    public static function unkeep(File $file): void
    {
        $file->forceFill(['kept_at' => null])->save();
    }

    private static function keepFull(User $owner): MediaRefused
    {
        return match (MediaLimits::tier($owner)) {
            MediaLimits::FREE => MediaRefused::quota('keep_quota', 'upgrade_pro', 'Keep is on Pro and Max. Upgrade to keep files.'),
            MediaLimits::PRO => MediaRefused::quota('keep_quota', 'upgrade_max', 'Your Keep storage is full. Upgrade to Max for more.'),
            default => MediaRefused::quota('keep_quota', 'contact_sales', 'Your Keep storage is full. Contact us for more storage.'),
        };
    }

    /** @return Builder */
    private static function queuedPostIds()
    {
        return DB::table('social_posts')
            ->whereIn('status', [SocialPostStatus::Draft->value, SocialPostStatus::Scheduled->value])
            ->select('id');
    }

    /** @return Builder */
    private static function queuedFileIds()
    {
        return DB::table('social_post_files')
            ->whereIn('social_post_id', self::queuedPostIds())
            ->select('file_id');
    }
}
