<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Sent once, right after a waitlist confirm: the person's share link. Transactional. */
class WaitlistOnTheList extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $shareUrl) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("You're on the Creatif Space MCP waitlist")
            ->greeting("You're on the list.")
            ->line("We'll email you when your invite is ready.")
            ->line('Want in sooner? Share your link. When 3 people confirm, you move to the priority batch.')
            ->action('Your link', $this->shareUrl)
            ->line($this->shareUrl);
    }
}
