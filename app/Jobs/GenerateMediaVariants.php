<?php

namespace App\Jobs;

use App\Models\File;
use App\Support\Media\MediaVariants;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Thumbnail / feed (and a video's poster) variants of one library file,
 * after its upload is confirmed or its bytes change (MediaVariants).
 */
class GenerateMediaVariants implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(public int $fileId) {}

    public function uniqueId(): string
    {
        return (string) $this->fileId;
    }

    public function handle(): void
    {
        $file = File::query()->find($this->fileId);
        if ($file === null || ! MediaVariants::isCandidate($file) || MediaVariants::settled($file)) {
            return;
        }
        // A 50 MP photo decodes to ~200 MB in GD.
        @ini_set('memory_limit', '512M');
        MediaVariants::generate($file);
    }
}
