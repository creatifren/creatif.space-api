<?php

namespace App\Jobs;

use App\Models\SpaceArchive;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;
use ZipArchive;

/**
 * Builds the "Download all" zip for one Space.
 *
 * Streamed both ways: each object is copied R2 → local temp file → the
 * archive, and the finished zip is streamed back up. A Space of 500 photos
 * never has more than one photo in memory at a time.
 *
 * The temp directory is removed whichever way this ends. A queue outage
 * that leaves half-built archives on disk is how a server runs out of it.
 */
class BuildSpaceArchive implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 900;

    public function __construct(public SpaceArchive $archive) {}

    public function handle(): void
    {
        $archive = $this->archive->fresh();

        if ($archive === null || $archive->status !== SpaceArchive::STATUS_PENDING) {
            return; // Superseded or already built.
        }

        $space = $archive->space;
        $files = $space->items()->with('file')->get()
            ->map(fn ($item) => $item->file)
            ->filter();

        $total = (int) $files->sum('size_bytes');

        if ($total > SpaceArchive::MAX_BYTES) {
            $archive->update([
                'status' => SpaceArchive::STATUS_FAILED,
                'failure_reason' => 'too_large',
            ]);

            return;
        }

        $dir = storage_path('app/private/archives/'.$archive->ulid);
        $zipPath = $dir.'/archive.zip';

        try {
            @mkdir($dir, 0755, true);

            $zip = new ZipArchive;
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Could not open the archive for writing.');
            }

            $used = [];
            foreach ($files as $file) {
                $local = $dir.'/'.$file->ulid;
                $source = Storage::disk($file->disk)->readStream($file->path);

                if (! is_resource($source)) {
                    continue; // An object we cannot read is skipped, not fatal.
                }

                $sink = fopen($local, 'wb');
                stream_copy_to_stream($source, $sink);
                fclose($sink);
                fclose($source);

                /* Two files can share a name inside one Space — the zip
                   would silently keep one. Suffix the repeats. */
                $name = $file->name;
                if (isset($used[$name])) {
                    $ext = pathinfo($name, PATHINFO_EXTENSION);
                    $base = pathinfo($name, PATHINFO_FILENAME);
                    $name = $base.' ('.(++$used[$name]).')'.($ext !== '' ? '.'.$ext : '');
                } else {
                    $used[$file->name] = 1;
                }

                $zip->addFile($local, $name);
            }

            $zip->close();

            $path = 'archives/'.$space->ulid.'/'.$archive->ulid.'.zip';
            $stream = fopen($zipPath, 'rb');
            Storage::disk('s3')->writeStream($path, $stream);
            if (is_resource($stream)) {
                fclose($stream);
            }

            $archive->update([
                'status' => SpaceArchive::STATUS_READY,
                'path' => $path,
                'size_bytes' => filesize($zipPath) ?: null,
                'expires_at' => now()->addHours(SpaceArchive::TTL_HOURS),
            ]);
        } catch (Throwable $e) {
            if ($this->attempts() >= $this->tries) {
                $archive->update([
                    'status' => SpaceArchive::STATUS_FAILED,
                    'failure_reason' => 'build_failed',
                ]);
            }

            throw $e;
        } finally {
            $this->cleanUp($dir);
        }
    }

    private function cleanUp(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach ((array) glob($dir.'/*') as $path) {
            @unlink((string) $path);
        }

        @rmdir($dir);
    }
}
