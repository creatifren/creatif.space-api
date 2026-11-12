<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The one welcome email (App\Support\Onboarding::welcome): the two first
 * steps, each a link into the app — connect a first channel, and connect
 * to Claude. Account email, mail only. No provider is named; the footer is
 * the slogan alone (ApprovalMailFooter::sloganOnly).
 */
class WelcomeEmail extends Notification implements ShouldQueue
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
        $app = rtrim((string) config('app.frontend_url'), '/');

        return ApprovalMailFooter::sloganOnly((new MailMessage)
            ->subject('Welcome to Creatif Space')
            ->greeting('Hi '.$notifiable->name.',')
            ->line('Your account is ready. Two steps get your first post approved and out:')
            ->line('**1. Connect your first channel.** The social account your posts go out to. It takes a minute.')
            ->action('Connect your first channel', $app.'/post/channels')
            ->line('**2. Connect to Claude.** Ask Claude to draft, schedule or check your posts from any chat. [Connect to Claude]('.$app.'/connect/claude)')
            ->line('Questions? Reply to this email and a person will answer.'));
    }
}
