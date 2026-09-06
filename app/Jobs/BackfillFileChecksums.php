<?php

namespace App\Jobs;

use App\Models\File;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Files stored before the upload path recorded a checksum have none, so
 * duplicate detection cannot see them. This asks R2 for each object's ETag
 * once and fills the gap.
 *
 * Bounded per run: a HEAD per object is cheap but not free, and an account
 * with 50k files should not hold the queue for one night's worth of work.
 * It runs daily and simply picks up where it left off — the `whereNull`
 * makes the job its own cursor.
 */
class BackfillFileChecksums implements ShouldQueue
{
    use Queueable;

    /** Objects to look at per run. */
    public const BATCH = 500;

    public function handle(): void
    {
        File::query()
            ->whereNull('checksum')
            ->where('status', File::STATUS_READY)
            ->orderBy('id')
            ->limit(self::BATCH)
            ->each(function (File $file): void {
                try {
                    $file->forceFill([
                        'checksum' => File::md5FromEtag(
                            Storage::disk($file->disk)->checksum($file->path),
                        ),
                    ])->save();
                } catch (Throwable) {
                    /* An object we cannot reach — deleted underneath us, or
                       a disk hiccup — must not stop the rest of the batch.
                       It stays null and the next run tries again. */
                }
            });
    }
}
