<?php

namespace App\Jobs;

use App\Enums\SubmissionStatus;
use App\Models\File;
use App\Models\FileRequestSubmission;
use App\Notifications\FilesReceived;
use App\Notifications\StorageAlmostFull;
use App\Support\PlanQuota;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Push a submission's files into the owner's storage (R2).
 *
 * Runs here rather than in the request because someone submitting from a
 * phone on a train must not hold the connection open through a storage
 * round-trip. The sender was already told their files went through, so
 * failure here is never silent: the row is marked failed and the owner is
 * told, because they are the one who can chase it.
 *
 * The staged copies are deleted whichever way this ends — a queue outage
 * must not leave submissions piling up on local disk.
 */
class UploadSubmissionFiles implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    /**
     * @param  list<array{path: string, name: string, mime: string}>  $staged
     */
    public function __construct(
        public FileRequestSubmission $submission,
        public array $staged,
    ) {}

    public function handle(): void
    {
        $request = $this->submission->fileRequest;
        $owner = $request->user;
        $landed = [];

        try {
            foreach ($this->staged as $stagedFile) {
                $size = Storage::disk('local')->size($stagedFile['path']);

                if (! PlanQuota::canStore($owner, $size)) {
                    $this->giveUp('Storage is full — free some space or upgrade the plan.', $landed);

                    return;
                }

                $ulid = (string) str()->ulid();
                $ext = strtolower(pathinfo($stagedFile['name'], PATHINFO_EXTENSION));

                $file = File::create([
                    'user_id' => $owner->id,
                    'ulid' => $ulid,
                    // Explicit, not the column default: $file->disk is read
                    // below before any refresh would hydrate the DB default.
                    'disk' => 's3',
                    'path' => 'requests/'.$request->ulid.'/'.$ulid.($ext !== '' ? '.'.$ext : ''),
                    'name' => $stagedFile['name'],
                    'mime_type' => $stagedFile['mime'],
                    'size_bytes' => $size,
                    'status' => File::STATUS_PENDING,
                    'source' => 'request',
                    'source_meta' => [
                        'file_request_ulid' => $request->ulid,
                        'submission_ulid' => $this->submission->ulid,
                    ],
                ]);

                // Stream, not string: a 100 MB submission stays off the heap.
                $stream = Storage::disk('local')->readStream($stagedFile['path']);
                Storage::disk($file->disk)->writeStream($file->path, $stream);
                if (is_resource($stream)) {
                    fclose($stream);
                }

                $file->update(['status' => File::STATUS_READY]);

                $landed[] = ['name' => $stagedFile['name'], 'file_id' => $file->ulid];
            }
        } catch (Throwable $e) {
            // A network blip should retry; the last attempt gives up loudly.
            if ($this->attempts() < $this->tries) {
                throw $e;
            }

            $this->giveUp('The upload kept failing.', $landed);

            return;
        }

        $this->submission->forceFill([
            'files' => $landed,
            'status' => SubmissionStatus::Stored,
        ])->save();

        $this->cleanUp();

        $owner->notify(new FilesReceived($this->submission));

        StorageAlmostFull::checkAndSend($owner);
    }

    /**
     * Whatever the queue does next, the staged copies go — including when
     * the job is abandoned after its last retry.
     */
    public function failed(?Throwable $exception): void
    {
        $this->cleanUp();
    }

    /**
     * @param  list<array{name: string, file_id: string}>  $landed
     */
    private function giveUp(string $reason, array $landed): void
    {
        $this->submission->forceFill([
            // Whatever did land is kept: a partial delivery is still a
            // delivery, and the owner should see which files made it.
            'files' => $landed,
            'status' => SubmissionStatus::Failed,
            'failure_reason' => $reason,
        ])->save();

        $this->cleanUp();

        $this->submission->fileRequest->user->notify(
            new FilesReceived($this->submission),
        );
    }

    private function cleanUp(): void
    {
        Storage::disk('local')->deleteDirectory(
            'submissions/'.$this->submission->ulid,
        );
    }
}
