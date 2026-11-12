<?php

namespace App\Notifications;

use App\Enums\NotificationType;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Day 2, once ever (App\Support\Onboarding::nudge): no channel is
 * connected yet. Email only, and only while Settings → Notifications →
 * Product news (email) is on — checked again here at send time.
 */
class ConnectChannelNudge extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return $notifiable instanceof User && $notifiable->wants(NotificationType::ProductNews, 'email') ? ['mail'] : [];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $app = rtrim((string) config('app.frontend_url'), '/');

        return ApprovalMailFooter::sloganOnly((new MailMessage)
            ->subject('Connect your first channel')
            ->greeting('Hi '.$notifiable->name.',')
            ->line('Your account has no channel connected yet. A channel is the social account your posts go out to: nothing can be scheduled or published until one is connected.')
            ->action('Connect your first channel', $app.'/post/channels')
            ->line('Rather work from a chat? [Connect to Claude]('.$app.'/connect/claude) and ask it to draft your first post.')
            ->line('You can turn these emails off in Settings → Notifications → Product news.'));
    }
}
