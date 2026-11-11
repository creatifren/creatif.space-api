<?php

namespace App\Notifications;

use App\Models\SocialApprovalLink;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * An approval link made before approvers signed in was just decided on by
 * a signed-in person, and is now tied to their email (trust on first use).
 * Always mailed: the owner must be able to see who now speaks for the
 * client, and say so if it is the wrong person.
 */
class ApprovalLinkBound extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public SocialApprovalLink $link,
        public string $email,
        public string $decision,
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
        $subject = $this->decision === 'approved'
            ? "Approved by {$this->email}"
            : "Changes requested by {$this->email}";

        return (new MailMessage)
            ->subject($subject)
            ->greeting('Hi '.$notifiable->name.',')
            ->line($subject.($this->link->title ? " on “{$this->link->title}”" : '').'.')
            ->line("This link is now tied to {$this->email}: only someone signed in with that address can decide on it. If that is not your client, revoke the link and send a new one.")
            ->action('Open the approval link', config('app.frontend_url').'/post/approvals?link='.$this->link->ulid);
    }
}
