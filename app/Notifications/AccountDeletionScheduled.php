<?php

namespace App\Notifications;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Deletion asked for: when it happens, and how to stop it. Transactional. */
class AccountDeletionScheduled extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public CarbonImmutable $purgeAt, public ?string $playManageUrl = null) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $date = $this->purgeAt->utc()->format('j F Y');

        $mail = (new MailMessage)
            ->subject("Deletion scheduled for {$date}")
            ->greeting('Your account will be deleted')
            ->line("Your Creatif Space account and everything in it — posts, Spaces, files, connected channels — will be deleted for good on {$date}.")
            ->line('We have already signed you out everywhere, cancelled your scheduled posts, closed your approval links and disconnected your channels.')
            ->line('Changed your mind? Sign in before that date and choose "Keep my account" (Settings → Account). Your account comes back as it is now.');

        if ($this->playManageUrl !== null) {
            $mail->line('Your plan is billed through Google Play. To stop it renewing, cancel it in Google Play:')
                ->action('Manage in Google Play', $this->playManageUrl);
        } else {
            $mail->action('Keep my account', rtrim((string) config('app.frontend_url'), '/').'/settings?section=account');
        }

        return $mail->line('Invoices and payment records are kept as the law requires, without your name or address.');
    }
}
