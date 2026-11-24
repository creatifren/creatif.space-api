<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** To the team inbox: a visitor reported a public page ("Report this page"). */
class PageReported extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $url,
        public string $reason,
        public ?string $ownerEmail = null,
        public ?int $ownerId = null,
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
        return (new MailMessage)
            ->subject('Page reported ('.$this->reason.'): '.str($this->url)->limit(70))
            ->greeting('A visitor reported a public page')
            ->line('Page: '.$this->url)
            ->line('Reason: '.$this->reason)
            ->line('Owner: '.($this->ownerEmail !== null ? $this->ownerEmail.' (user '.$this->ownerId.')' : 'not found from the address'))
            ->line('Check the page, then hide or remove it in Filament if it breaks the rules.');
    }
}
