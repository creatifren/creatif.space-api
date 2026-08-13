<?php

namespace App\Notifications;

use App\Enums\NotificationType;
use App\Models\Space;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "File changed in Drive" — a file moved on and took its approvals with it.
 * The one piece of bad news the product volunteers, because a stale
 * approval is worse than no approval.
 */
class ApprovalsVoided extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Space $space,
        public string $fileName,
        public int $count,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return NotificationChannels::for($notifiable, NotificationType::ApprovalCancelled);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $plural = $this->count === 1 ? 'approval is' : 'approvals are';

        return (new MailMessage)
            ->subject('Approval void: '.$this->space->title)
            ->greeting('Hi '.$notifiable->name.',')
            ->line("The file {$this->fileName} changed in Google Drive.")
            ->line("{$this->count} {$plural} now void in {$this->space->title}.")
            ->action('Review the Space', config('app.frontend_url').'/insights?tab=approval')
            ->line('Send it out again once the new version is the one you want signed off.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => NotificationType::ApprovalCancelled->value,
            'space_id' => $this->space->ulid,
            'space_title' => $this->space->title,
            'file_name' => $this->fileName,
            'count' => $this->count,
        ];
    }
}
