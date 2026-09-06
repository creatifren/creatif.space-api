<?php

namespace App\Jobs;

use App\Models\DriveAccount;
use App\Models\File;
use App\Services\GoogleDriveService;
use App\Support\PlanQuota;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * One-way copy: fetch metadata, download the bytes, push them to R2, done.
 * The imported file has no live link back to Drive — no sync, no version
 * tracking, no access to lose.
 */
class ImportDriveFile implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(
        public DriveAccount $account,
        public string $fileId,
        // Default null: jobs queued before this argument existed still unserialize.
        public ?int $folderId = null,
    ) {}

    public function handle(GoogleDriveService $drive): void
    {
        $meta = $drive->fileMetadata($this->account, $this->fileId);

        // Gone, access lost, or a Google-native doc/folder — those have no
        // bytes at alt=media and can only 403 confusingly. Skip silently:
        // no row was promised for a file that cannot be imported.
        if ($meta === null || str_starts_with((string) ($meta['mimeType'] ?? ''), 'application/vnd.google-apps.')) {
            return;
        }

        $owner = $this->account->user;
        $size = (int) ($meta['size'] ?? 0);

        if (! PlanQuota::canStore($owner, $size)) {
            Log::info("Drive import skipped for user {$owner->id}: storage quota full.");

            return;
        }

        $ulid = (string) str()->ulid();
        $name = (string) ($meta['name'] ?? 'file');

        $file = File::create([
            'user_id' => $owner->id,
            'folder_id' => $this->folderId,
            'ulid' => $ulid,
            // Explicit, not the column default: $file->disk is read below
            // before any refresh would hydrate the DB default.
            'disk' => 's3',
            'path' => File::keyFor($owner, $ulid, $name),
            'name' => $name,
            'mime_type' => (string) ($meta['mimeType'] ?? 'application/octet-stream'),
            'size_bytes' => $size,
            'checksum' => $meta['md5Checksum'] ?? null,
            'status' => File::STATUS_PENDING,
            'width' => $meta['imageMediaMetadata']['width'] ?? null,
            'height' => $meta['imageMediaMetadata']['height'] ?? null,
            'exif' => $meta['imageMediaMetadata'] ?? null,
            'source' => 'drive_import',
            'source_meta' => [
                'provider_file_id' => $this->fileId,
                'drive_email' => $this->account->email,
            ],
        ]);

        $tmp = tempnam(sys_get_temp_dir(), 'drive-import-');

        try {
            if ($tmp === false || ! $drive->download($this->account, $this->fileId, $tmp)) {
                $file->update(['status' => File::STATUS_FAILED]);

                return;
            }

            $stream = fopen($tmp, 'r');
            Storage::disk($file->disk)->writeStream($file->path, $stream);
            if (is_resource($stream)) {
                fclose($stream);
            }

            $file->update([
                'size_bytes' => Storage::disk($file->disk)->size($file->path),
                'status' => File::STATUS_READY,
            ]);

            \App\Notifications\StorageAlmostFull::checkAndSend($owner);
        } finally {
            if ($tmp !== false && is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }
}
