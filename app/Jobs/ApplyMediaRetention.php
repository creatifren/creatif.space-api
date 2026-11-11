<?php

namespace App\Jobs;

use App\Http\Controllers\Api\V1\FileController;
use App\Models\File;
use App\Models\User;
use App\Support\Media\MediaRetention;
use App\Support\Media\RetentionVerdict;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Media v2 retention, daily. Every decision comes from MediaRetention —
 * the same calculator the API shows as auto_delete_at.
 *
 * Mode (config media.retention_mode / MEDIA_RETENTION_MODE):
 * - report (default): nothing is deleted; what WOULD be is logged per user
 *   and returned as a summary (php artisan media:retention prints it).
 * - delete: due files are deleted through the model path
 *   (FileController::purge: version objects, the object, then the row), so
 *   R2 and the database go together.
 *
 * Chunked by file id; idempotent (a deleted file is simply not found next
 * time); each due file is re-decided from fresh rows right before it is
 * deleted, so a file attached to a post a moment ago is not taken.
 */
class ApplyMediaRetention implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    /** @var array<int, string> owner id => ulid, for this run only */
    private array $owners = [];

    public function __construct(public ?string $mode = null) {}

    /**
     * @return array{mode: string, files: int, bytes: int, failed: int, users: array<string, array{tier: string, files: int, bytes: int, rules: array<string, int>}>}
     */
    public function handle(): array
    {
        $mode = ($this->mode ?? config('media.retention_mode')) === 'delete' ? 'delete' : 'report';
        $summary = ['mode' => $mode, 'files' => 0, 'bytes' => 0, 'failed' => 0, 'users' => []];
        $chunk = max(1, (int) config('media.retention.chunk')) * 10;

        File::query()
            ->where('status', File::STATUS_READY)
            ->where(fn ($q) => $q->where('mime_type', 'like', 'image/%')->orWhere('mime_type', 'like', 'video/%'))
            ->chunkById($chunk, function ($files) use ($mode, &$summary) {
                MediaRetention::flush();
                $verdicts = MediaRetention::prime($files);
                $now = now()->toImmutable();

                foreach ($files as $file) {
                    /** @var File $file */
                    $verdict = $verdicts[$file->id] ?? null;
                    if ($verdict === null || ! $verdict->isDue($now)) {
                        continue;
                    }

                    if ($mode === 'delete' && ! $this->deleteIfStillDue($file)) {
                        $summary['failed']++;

                        continue;
                    }

                    $this->count($summary, $file, $verdict);
                }
            });

        MediaRetention::flush();

        foreach ($summary['users'] as $ulid => $row) {
            Log::info('media.retention.user', ['mode' => $mode, 'user' => $ulid] + $row);
        }
        Log::info('media.retention', [
            'mode' => $mode,
            'files' => $summary['files'],
            'bytes' => $summary['bytes'],
            'users' => count($summary['users']),
            'failed' => $summary['failed'],
        ]);

        return $summary;
    }

    private function deleteIfStillDue(File $file): bool
    {
        $fresh = File::query()->find($file->id);
        if ($fresh === null) {
            return false;
        }

        MediaRetention::flush();
        $verdict = MediaRetention::prime([$fresh])[$fresh->id] ?? null;
        if ($verdict === null || ! $verdict->isDue()) {
            return false;
        }

        try {
            FileController::purge($fresh);

            return true;
        } catch (Throwable $e) {
            Log::warning('media.retention.delete_failed', ['file' => $fresh->ulid, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * @param  array{mode: string, files: int, bytes: int, failed: int, users: array<string, array{tier: string, files: int, bytes: int, rules: array<string, int>}>}  $summary
     */
    private function count(array &$summary, File $file, RetentionVerdict $verdict): void
    {
        $this->owners[$file->user_id] ??= (string) (User::query()->whereKey($file->user_id)->value('ulid') ?? $file->user_id);
        $ulid = $this->owners[$file->user_id];

        $summary['files']++;
        $summary['bytes'] += (int) $file->size_bytes;
        $summary['users'][$ulid] ??= ['tier' => $verdict->rule === RetentionVerdict::PAID_AFTER_PUBLISH ? 'paid' : 'free', 'files' => 0, 'bytes' => 0, 'rules' => []];
        $summary['users'][$ulid]['files']++;
        $summary['users'][$ulid]['bytes'] += (int) $file->size_bytes;
        $summary['users'][$ulid]['rules'][$verdict->rule] = ($summary['users'][$ulid]['rules'][$verdict->rule] ?? 0) + 1;
    }
}
