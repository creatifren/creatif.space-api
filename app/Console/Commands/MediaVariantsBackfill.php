<?php

namespace App\Console\Commands;

use App\Models\File;
use App\Support\Media\MediaVariants;
use Illuminate\Console\Command;

/**
 * Make the thumbnail / feed / poster variants of library files uploaded
 * before they existed (q202611141319). Runs here, one file at a time;
 * safe to stop and re-run (done files are skipped).
 *
 *   php artisan media:variants --dry-run     # how many, nothing written
 *   php artisan media:variants --limit=500
 */
class MediaVariantsBackfill extends Command
{
    protected $signature = 'media:variants
        {--dry-run : Only count the files that still need variants}
        {--limit=0 : Stop after this many files (0 = all)}';

    protected $description = 'Make the approval-page thumbnail / feed / poster variants of existing image and video files';

    public function handle(): int
    {
        $query = File::query()
            ->where('status', File::STATUS_READY)
            ->where(fn ($q) => $q->where('mime_type', 'like', 'image/%')->orWhere('mime_type', 'like', 'video/%'))
            ->whereNull('variants');

        $todo = (clone $query)->count();
        if ($this->option('dry-run')) {
            $this->line("{$todo} files need variants (dry run: nothing was written).");

            return self::SUCCESS;
        }

        @ini_set('memory_limit', '512M');
        $limit = max(0, (int) $this->option('limit'));
        $done = 0;
        $failed = 0;
        $query->orderBy('id')->chunkById(100, function ($files) use (&$done, &$failed, $limit) {
            foreach ($files as $file) {
                if ($limit > 0 && $done >= $limit) {
                    return false;
                }
                $result = MediaVariants::generate($file);
                $done++;
                if (isset($result['error'])) {
                    $failed++;
                }
            }

            return true;
        });

        $this->line("{$done} of {$todo} files processed".($failed > 0 ? ", {$failed} left on the original (unreadable, too large, or no video tool)" : '').'.');

        return self::SUCCESS;
    }
}
