<?php

namespace App\Notifications;

use App\Models\TeamMember;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Come and work in my account."
 *
 * Mail only, and via() says so directly rather than going through
 * NotificationChannels: the recipient may not have an account yet, so
 * there are no stored preferences to read and no bell to ring.
 */
class TeamInvited extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public TeamMember $member) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $owner = $this->member->owner;

        return (new MailMessage)
            ->subject($owner->name.' added you to their team on Creatif Space')
            ->greeting('Hi,')
            ->line($owner->name.' has given you a seat on their Creatif Space account.')
            ->line('Sign in with this email address and their Spaces and files appear alongside your own.')
            ->action('Sign in', config('app.frontend_url').'/login')
            ->line('Their earnings, billing and account settings stay theirs — a seat is for the work, not the money.');
    }
}
