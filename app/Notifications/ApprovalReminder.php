<?php

namespace App\Notifications;

use App\Models\SocialApprovalLink;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The owner's "Send a reminder" (POST /social-approval-links/{id}/remind):
 * the approver is asked once more to review the posts still waiting, on
 * the same link. Client-facing, so it carries the approval footer.
 */
class ApprovalReminder extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public SocialApprovalLink $link,
        public int $waiting,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $editor = $this->link->user->name;
        $posts = $this->waiting === 1 ? 'one post' : "{$this->waiting} posts";

        $mail = (new MailMessage)
            ->subject("Reminder: {$editor} is waiting for your approval")
            ->line("{$editor} is still waiting for your answer on {$posts}".($this->link->title ? " in “{$this->link->title}”" : '').'.')
            ->action('Review the posts', $this->link->url())
            ->line('Approve, or ask for a change, from the same link as before.');

        return ApprovalMailFooter::on($mail);
    }
}
