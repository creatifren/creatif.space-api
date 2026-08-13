<?php

namespace App\Jobs;

use App\Models\DriveAccount;
use App\Models\DriveFile;
use App\Services\GoogleDriveService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Traverse one Drive folder page by page, upserting children and fanning
 * out into subfolders. Each page re-dispatches itself so a huge folder
 * never pins a worker.
 */
class SyncDriveFolder implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public function __construct(
        public DriveAccount $account,
        public string $folderId,
        public ?string $pageToken = null,
        public int $depth = 0,
    ) {}

    public function handle(GoogleDriveService $drive): void
    {
        // Safety valve on runaway nesting.
        if ($this->depth > 10) {
            return;
        }

        $page = $drive->listChildren($this->account, $this->folderId, $this->pageToken);

        foreach ($page['files'] as $meta) {
            $isFolder = ($meta['mimeType'] ?? '') === 'application/vnd.google-apps.folder';
            $version = $meta['md5Checksum'] ?? $meta['version'] ?? null;

            /** @var DriveFile $file */
            $file = DriveFile::query()->firstOrNew([
                'drive_account_id' => $this->account->id,
                'provider_file_id' => (string) $meta['id'],
            ]);

            $file->fill([
                'parent_folder_id' => $this->folderId,
                'name' => (string) ($meta['name'] ?? 'Untitled'),
                'mime_type' => (string) ($meta['mimeType'] ?? 'application/octet-stream'),
                'size_bytes' => isset($meta['size']) ? (int) $meta['size'] : null,
                'thumbnail_url' => $meta['thumbnailLink'] ?? null,
                'version_hash' => $version !== null ? (string) $version : null,
                'exif' => $meta['imageMediaMetadata'] ?? null,
                'is_folder' => $isFolder,
            ]);
            $file->drive_account_id = $this->account->id;
            $file->forceFill([
                'access_lost_at' => null,
                'last_synced_at' => now(),
            ])->save();

            if ($isFolder) {
                self::dispatch($this->account, (string) $meta['id'], null, $this->depth + 1);
            }
        }

        if ($page['nextPageToken'] !== null) {
            self::dispatch($this->account, $this->folderId, $page['nextPageToken'], $this->depth);
        }

        $this->account->forceFill(['last_synced_at' => now()])->save();
    }
}
