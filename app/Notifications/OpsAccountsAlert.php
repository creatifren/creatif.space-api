<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * To the owner: connected social accounts reached a step (80 / 900). A count
 * only — no user, handle or email.
 */
class OpsAccountsAlert extends Notification
{
    use Queueable;

    public function __construct(public int $count, public int $step) {}

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
            ->subject("Creatif Space: {$this->count} connected social accounts")
            ->greeting("{$this->step}+ connected accounts")
            ->line("{$this->count} social accounts are connected on the publishing relay (Outstand) right now.")
            ->line('Check the Outstand plan size before it fills up (the $19 plan holds 100).');
    }
}
