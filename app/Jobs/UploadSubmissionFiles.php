<?php

namespace App\Jobs;

use App\Enums\SubmissionStatus;
use App\Models\FileRequestSubmission;
use App\Notifications\FilesReceived;
use App\Services\GoogleDriveService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Push a submission's files into the owner's Drive.
 *
 * Runs here rather than in the request because someone submitting from a
 * phone on a train must not hold the connection open through a Drive
 * round-trip. The sender was already told their files went through, so
 * failure here is never silent: the row is marked failed and the owner is
 * told, because they are the one who can chase it.
 *
 * The staged copies are deleted whichever way this ends — this app stores
 * no files, and a queue outage must not turn that into a lie.
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

    public function handle(GoogleDriveService $drive): void
    {
        $request = $this->submission->fileRequest;
        $landed = [];

        try {
            foreach ($this->staged as $file) {
                $id = $drive->upload(
                    $request->driveAccount,
                    $request->target_folder_id,
                    Storage::disk('local')->path($file['path']),
                    $file['name'],
                    $file['mime'],
                );

                if ($id === null) {
                    // Drive refused: the folder is gone, or the grant no
                    // longer reaches it. Neither is worth retrying.
                    $this->giveUp('That folder can’t be reached in Drive any more.', $landed);

                    return;
                }

                $landed[] = ['name' => $file['name'], 'provider_file_id' => $id];
            }
        } catch (Throwable $e) {
            // A network blip should retry; the last attempt gives up loudly.
            if ($this->attempts() < $this->tries) {
                throw $e;
            }

            $this->giveUp('The upload to Drive kept failing.', $landed);

            return;
        }

        $this->submission->forceFill([
            'files' => $landed,
            'status' => SubmissionStatus::Stored,
        ])->save();

        $this->cleanUp();

        $request->user->notify(new FilesReceived($this->submission));
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
     * @param  list<array{name: string, provider_file_id: string}>  $landed
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
