<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** "Confirm your spot" — the double opt-in for a public waitlist. Mail only. */
class WaitlistConfirm extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $url) {}

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
            ->subject('Confirm your spot on the Creatif Space MCP waitlist')
            ->greeting('Hi,')
            ->line('Someone — hopefully you — asked to join the Creatif Space MCP waitlist with this address.')
            ->action('Confirm my spot', $this->url)
            ->line('The link works for 7 days. If this wasn’t you, ignore this email and nothing happens.');
    }
}
