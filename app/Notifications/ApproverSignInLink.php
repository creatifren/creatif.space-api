<?php

namespace App\Notifications;

use App\Models\SocialApprovalLink;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The approver's one-time sign-in link. Sent now, not queued: it expires
 * in 15 minutes and the person is waiting on the page for it.
 */
class ApproverSignInLink extends Notification
{
    public function __construct(
        public SocialApprovalLink $link,
        public string $url,
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

        return (new MailMessage)
            ->subject("Sign in to review {$editor}’s posts")
            ->line("{$editor} asked you to review posts on creatif.space.")
            ->action('Sign in and review', $this->url)
            ->line('This link works once and expires in 15 minutes. If you did not ask for it, ignore this email.');
    }
}
