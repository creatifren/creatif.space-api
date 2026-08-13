<?php

namespace App\Notifications;

use App\Enums\NotificationType;
use App\Models\Space;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Someone opens my Space" — the third switch in Settings → Notifications,
 * which has existed since Fase 4 with nothing behind it. The beacon finally
 * raises it.
 *
 * Sent on the first visit from a browser in a rolling day, not on every
 * page load, and never for crawlers. A link that goes around a WhatsApp
 * group should feel like news, not like a stuck doorbell.
 */
class SpaceOpened extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Space $space) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return NotificationChannels::for($notifiable, NotificationType::SpaceOpened);
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Opened: '.$this->space->title)
            ->greeting('Hi '.$notifiable->name.',')
            ->line('Someone just opened '.$this->space->title.'.')
            ->action('See what they looked at', config('app.frontend_url').'/insights?tab=analytics')
            ->line('You can switch this off in Settings → Notifications.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => NotificationType::SpaceOpened->value,
            'space_id' => $this->space->ulid,
            'space_title' => $this->space->title,
        ];
    }
}
