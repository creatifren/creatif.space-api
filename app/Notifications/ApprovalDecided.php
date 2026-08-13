<?php

namespace App\Notifications;

use App\Enums\ApprovalStatus;
use App\Enums\NoteAuthor;
use App\Enums\NotificationType;
use App\Models\Approval;
use App\Models\Space;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "A client gives approval" — the first switch in Settings → Notifications.
 * Sent the moment a decision lands, because the whole point of the product
 * is not having to ask whether it landed.
 */
class ApprovalDecided extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Approval $approval,
        public Space $space,
        /** How many files this one gesture covered — 1 for a single click. */
        public int $count = 1,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return NotificationChannels::for($notifiable, NotificationType::ApprovalDecided);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $client = $this->approval->client->displayName();
        $approved = $this->approval->status === ApprovalStatus::Approved;
        $what = $this->count > 1
            ? "{$this->count} files"
            : $this->approval->spaceItem->driveFile->name;

        $message = (new MailMessage)
            ->subject(($approved ? 'Approved: ' : 'Revision asked: ').$this->space->title)
            ->greeting('Hi '.$notifiable->name.',')
            ->line($approved
                ? "{$client} approved {$what} in {$this->space->title}."
                : "{$client} asked for a revision on {$what} in {$this->space->title}.");

        $note = $this->approval->notes->firstWhere('author_type', NoteAuthor::Client);
        if ($note !== null) {
            $message->line('“'.$note->body.'”');
        }

        return $message
            ->action('Open the Space', config('app.frontend_url').'/insights?tab=approval')
            ->line('The files stayed in your Drive the whole time.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => NotificationType::ApprovalDecided->value,
            'space_id' => $this->space->ulid,
            'space_title' => $this->space->title,
            'client_name' => $this->approval->client->displayName(),
            'file_name' => $this->approval->spaceItem->driveFile->name,
            'status' => $this->approval->status->value,
            'count' => $this->count,
        ];
    }
}
