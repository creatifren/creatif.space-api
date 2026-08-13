<?php

namespace App\Notifications;

use App\Enums\NotificationType;
use App\Enums\SubmissionStatus;
use App\Models\FileRequestSubmission;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Files arrived through a File Request — or didn't, and the owner is the
 * only person who can do anything about that. One notification covers both
 * because they are the same event from the owner's side: somebody sent you
 * something, here is where it got to.
 *
 * Sent from the upload job, once the files are actually in Drive, never
 * from the request that accepted them.
 */
class FilesReceived extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public FileRequestSubmission $submission) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return NotificationChannels::for($notifiable, NotificationType::FilesReceived);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $request = $this->submission->fileRequest;
        $sender = $this->submission->sender_name;
        $count = count($this->submission->files);
        $failed = $this->submission->status === SubmissionStatus::Failed;

        $message = (new MailMessage)
            ->subject(($failed ? 'Couldn’t save: ' : 'Files received: ').$request->title)
            ->greeting('Hi '.$notifiable->name.',');

        if ($failed) {
            $message
                ->line($sender.' sent files to '.$request->title.', but they couldn’t be saved to your Drive.')
                ->line($this->submission->failure_reason ?? 'The upload didn’t go through.')
                ->line($count > 0
                    ? $count.' of them did land — the rest did not.'
                    : 'Nothing was saved.');
        } else {
            $message->line(
                $sender.' sent '.$count.($count === 1 ? ' file' : ' files')
                .' to '.$request->title.'.',
            );

            if ($this->submission->message !== null) {
                $message->line('“'.$this->submission->message.'”');
            }
        }

        return $message
            ->action('Open Crefile', config('app.frontend_url').'/files')
            ->line('They went straight into the folder you picked — nothing was copied here.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => NotificationType::FilesReceived->value,
            'submission_id' => $this->submission->ulid,
            'request_title' => $this->submission->fileRequest->title,
            'sender_name' => $this->submission->sender_name,
            'file_count' => count($this->submission->files),
            'status' => $this->submission->status->value,
        ];
    }
}
