<?php

namespace App\Notifications;

use App\Enums\NotificationType;
use App\Models\SocialAccount;
use App\Support\ChannelHealth;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The daily channel check failed: posts to this channel would not go out.
 * Once per failure (ChannelHealth::notifyOnce). Same switch as a missed or
 * failed post ("Post missed / failed", publish_failed). No vendor name.
 */
class ChannelNeedsAttention extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public SocialAccount $account) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return NotificationChannels::for($notifiable, NotificationType::SocialPostFailed);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $app = rtrim((string) config('app.frontend_url'), '/');

        return (new MailMessage)
            ->subject('A channel needs attention: '.$this->account->label())
            ->greeting('Hi '.$notifiable->name.',')
            ->line('We checked your '.$this->account->label().' channel and it can’t be posted to right now.')
            ->line($this->reason())
            ->action('Open Channels', $app.'/post/channels')
            ->line('Posts scheduled to it will not go out until it is reconnected.');
    }

    public function reason(): string
    {
        return ChannelHealth::REASONS[(string) $this->account->health_reason] ?? 'Reconnect it to keep posting.';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => NotificationType::SocialPostFailed->value,
            'kind' => 'channel_needs_attention',
            'account_id' => $this->account->ulid,
            'channel' => $this->account->label(),
            'reason' => $this->reason(),
        ];
    }
}
