<?php

namespace App\Support\Media;

use App\Exceptions\MediaRefused;
use App\Models\File;
use App\Models\MediaUploadFlag;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * INTERNAL abuse limits on uploads — never in public copy, docs or any
 * message beyond "Upload limit reached, try again tomorrow":
 *
 * - presign rate per minute, per workspace;
 * - a daily byte and count budget (calendar day, app time);
 * - every cap hit (these, per-file caps, quotas) is counted per account;
 *   enough hits inside the window flag the account for a human to review
 *   (Filament → Upload flags). Nothing is ever banned automatically.
 */
final class MediaUploadGuard
{
    /**
     * @throws MediaRefused
     */
    public static function presign(User $owner, int $bytes): void
    {
        $key = 'media-presign:'.$owner->id;
        $perMinute = (int) config('media.abuse.presign_per_minute');

        if (RateLimiter::tooManyAttempts($key, $perMinute)) {
            self::hit($owner, 'presign_rate');

            throw MediaRefused::uploadLimit();
        }
        RateLimiter::hit($key, 60);

        $today = File::withTrashed()
            ->where('user_id', $owner->id)
            ->where('created_at', '>=', now()->startOfDay());

        $count = (clone $today)->count();
        $sum = (int) $today->sum('size_bytes');

        if ($count + 1 > (int) config('media.abuse.daily_count')) {
            self::hit($owner, 'daily_count');

            throw MediaRefused::uploadLimit();
        }

        if ($sum + $bytes > (int) config('media.abuse.daily_bytes')) {
            self::hit($owner, 'daily_bytes');

            throw MediaRefused::uploadLimit();
        }
    }

    /** Count one cap hit; flag the account once hits reach the threshold. */
    public static function hit(User $owner, string $reason): void
    {
        $windowDays = (int) config('media.abuse.flag_window_days');
        $threshold = (int) config('media.abuse.flag_after_hits');

        DB::transaction(function () use ($owner, $reason, $windowDays, $threshold) {
            $flag = MediaUploadFlag::query()->lockForUpdate()->firstOrNew(['user_id' => $owner->id]);
            $now = now();

            if ($flag->window_started_at === null || $flag->window_started_at->lt($now->copy()->subDays($windowDays))) {
                $flag->window_started_at = $now;
                $flag->hits = 0;
            }

            $flag->hits = (int) $flag->hits + 1;
            $flag->total_hits = (int) $flag->total_hits + 1;
            $flag->last_reason = $reason;
            $flag->last_hit_at = $now;

            if ($flag->flagged_at === null && $flag->hits >= $threshold) {
                $flag->flagged_at = $now;
                Log::warning('media.upload_flagged', ['user' => $owner->ulid, 'hits' => $flag->hits, 'reason' => $reason]);
            }

            $flag->save();
        });

        Log::info('media.upload_cap_hit', ['user' => $owner->ulid, 'reason' => $reason]);
    }
}
