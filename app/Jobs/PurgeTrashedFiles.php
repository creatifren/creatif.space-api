<?php

namespace App\Jobs;

use App\Http\Controllers\Api\V1\FileController;
use App\Models\File;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The Trash empties itself after File::TRASH_DAYS.
 *
 * `purge_at` is read, not recomputed: the date was stamped when the file
 * was deleted, so a file already in the bin keeps the countdown its owner
 * was shown even if the window changes later.
 */
class PurgeTrashedFiles implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        File::onlyTrashed()
            ->whereNotNull('purge_at')
            ->where('purge_at', '<=', now())
            // One at a time: an object we cannot reach should not strand
            // every file behind it.
            ->each(fn (File $file) => FileController::purge($file));
    }
}
