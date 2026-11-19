<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A client's monthly report, to the client's approver (v7 Report emails).
 * The link opens the report page; the sender name is the owner's
 * Preferences › Report emails › From name.
 */
class ClientReportReady extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $url,
        public string $client,
        public string $period,
        public string $preparedBy,
        public string $summary = '',
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->from((string) config('mail.from.address'), $this->preparedBy)
            ->subject("{$this->client} — your {$this->period} report")
            ->line("Here is the social media report for {$this->client}, {$this->period}.");
        if ($this->summary !== '') {
            $mail->line($this->summary);
        }

        return $mail
            ->action('Open the report', $this->url)
            ->line("Prepared by {$this->preparedBy}.");
    }
}
