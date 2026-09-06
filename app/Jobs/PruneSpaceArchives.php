<?php

namespace App\Jobs;

use App\Models\SpaceArchive;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

/**
 * Built archives are kept SpaceArchive::TTL_HOURS, then the object and the
 * row go. Without this every "Download all" ever pressed stays in the
 * bucket, billed monthly, for a zip nobody will open again.
 */
class PruneSpaceArchives implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        SpaceArchive::query()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->each(function (SpaceArchive $archive): void {
                if ($archive->path !== null) {
                    Storage::disk('s3')->delete($archive->path);
                }

                $archive->delete();
            });
    }
}
