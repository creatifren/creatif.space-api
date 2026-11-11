<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Deletion stopped within the grace period. Transactional. */
class AccountDeletionCancelled extends Notification implements ShouldQueue
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
            ->subject('Your account will not be deleted')
            ->greeting('Welcome back')
            ->line('You cancelled the deletion: your Creatif Space account stays.')
            ->line('Channels disconnected at the request need connecting again, and approval links made before it stay closed — share new ones. A plan cancelled at the request runs to the end of its period; choose a plan again to keep it.')
            ->action('Open Creatif Space', (string) config('app.frontend_url'));
    }
}
