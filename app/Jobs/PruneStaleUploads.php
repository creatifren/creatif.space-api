<?php

namespace App\Jobs;

use App\Models\File;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

/**
 * A presigned upload the browser never completed leaves a pending row
 * (and possibly an orphaned object) behind. A day is far past the 15-minute
 * presign window, so anything older is dead.
 */
class PruneStaleUploads implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        File::query()
            ->where('status', File::STATUS_PENDING)
            ->where('created_at', '<', now()->subDay())
            ->each(function (File $file): void {
                Storage::disk($file->disk)->delete($file->path);
                $file->delete();
            });
    }
}
