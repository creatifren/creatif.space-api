<?php

namespace App\Jobs;

use App\Models\DriveAccount;
use App\Models\DriveFile;
use App\Services\GoogleDriveService;
use App\Support\ApprovalVoider;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sync one picked file's metadata from Drive. Runs after the Picker hands
 * us file ids, and again on the periodic re-check.
 *
 * Also the version-change detector: when the md5/version moves, standing
 * approvals on the file are cancelled and the owner is told.
 */
class SyncDriveFile implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public function __construct(
        public DriveAccount $account,
        public string $providerFileId,
    ) {}

    public function handle(GoogleDriveService $drive): void
    {
        $meta = $drive->fileMetadata($this->account, $this->providerFileId);

        $file = DriveFile::query()->firstOrNew([
            'drive_account_id' => $this->account->id,
            'provider_file_id' => $this->providerFileId,
        ]);

        if ($meta === null) {
            // Gone or no longer shared with us — keep the row, flag it.
            if ($file->exists) {
                $file->forceFill(['access_lost_at' => $file->access_lost_at ?? now()])->save();
            }

            return;
        }

        $newVersion = $meta['md5Checksum'] ?? $meta['version'] ?? null;
        $versionChanged = $file->exists
            && $file->version_hash !== null
            && $newVersion !== null
            && $file->version_hash !== $newVersion;

        $file->fill([
            'parent_folder_id' => $meta['parents'][0] ?? null,
            'name' => (string) ($meta['name'] ?? $file->name ?? 'Untitled'),
            'mime_type' => (string) ($meta['mimeType'] ?? 'application/octet-stream'),
            'size_bytes' => isset($meta['size']) ? (int) $meta['size'] : null,
            'thumbnail_url' => $meta['thumbnailLink'] ?? null,
            'version_hash' => $newVersion !== null ? (string) $newVersion : null,
            'exif' => $meta['imageMediaMetadata'] ?? null,
            'is_folder' => ($meta['mimeType'] ?? '') === 'application/vnd.google-apps.folder',
        ]);
        $file->drive_account_id = $this->account->id;
        $file->forceFill([
            'access_lost_at' => null,
            'trashed_at' => ($meta['trashed'] ?? false) ? ($file->trashed_at ?? now()) : null,
            'last_synced_at' => now(),
        ])->save();

        if ($versionChanged) {
            // The rule lives in ApprovalVoider so it has exactly one home —
            // this job and the owner's Reset button both go through it.
            ApprovalVoider::fileChanged($file);
        }

        // Picked a folder? Fan out one traversal job for its children.
        if ($file->is_folder) {
            SyncDriveFolder::dispatch($this->account, $this->providerFileId);
        }

        $this->account->forceFill(['last_synced_at' => now()])->save();
    }
}
