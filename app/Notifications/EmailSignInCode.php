<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The 6-digit sign-in code (v7 sign-in). Sent now, not queued: it expires in
 * 10 minutes and the person is waiting on the page for it.
 */
class EmailSignInCode extends Notification
{
    public function __construct(public string $code) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("{$this->code} is your Creatif Space code")
            ->line('Your sign-in code:')
            ->line("**{$this->code}**")
            ->line('It works once and expires in 10 minutes. If you did not ask for it, ignore this email.');
    }
}
