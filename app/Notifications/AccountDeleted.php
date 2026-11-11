<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to the original address as the purge runs — not queued, so it goes
 * before the address is replaced. Transactional.
 */
class AccountDeleted extends Notification
{
    use Queueable;

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
            ->subject('Account deleted')
            ->greeting('Your account has been deleted')
            ->line('As you asked, your Creatif Space account and its content have been deleted. This cannot be undone.')
            ->line('Invoices and payment records are kept as the law requires, without your name or address.')
            ->line('Thank you for having been with us.');
    }
}
