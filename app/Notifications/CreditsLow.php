<?php

namespace App\Notifications;

use App\Enums\NotificationType;
use App\Models\User;
use App\Support\Credits;
use App\Support\Workspace;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Credits fell under 20% of the plan's Monthly Credits (backlog #2,
 * prototype v9.1 "Credits running low"): bell + email, once per Credits
 * cycle. Raised after anything spends Credits (a send, an AI reply).
 * Not switchable: scheduled posts stop when Credits run out.
 */
class CreditsLow extends Notification implements ShouldQueue
{
    use Queueable;

    /** Below this share of the monthly amount counts as low. */
    public const THRESHOLD = 0.2;

    public function __construct(public int $left, public int $monthly) {}

    /** Is this balance low against this monthly amount? A plan without Monthly Credits never is. */
    public static function isLow(int $left, int $monthly): bool
    {
        return $monthly > 0 && $left < (int) ceil($monthly * self::THRESHOLD);
    }

    /** Notify the owner once per cycle when the balance is low. Cheap no-op above the line. */
    public static function checkAndSend(User $user): void
    {
        $owner = Workspace::owner($user);
        $monthly = Credits::monthlyAmount($owner);
        if ($monthly <= 0) {
            return;
        }

        $left = Credits::total($owner);
        if (! self::isLow($left, $monthly)) {
            return;
        }

        $saidThisCycle = $owner->notifications()
            ->where('data->type', NotificationType::CreditsLow->value)
            ->where('created_at', '>=', Credits::cycleSince($owner))
            ->exists();

        if ($saidThisCycle) {
            return;
        }

        $owner->notify(new self($left, $monthly));
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return [...NotificationChannels::for($notifiable, NotificationType::CreditsLow), ...NotificationChannels::push($notifiable, 'credits')];
    }

    /** @return array{title: string, body: string, url: string} */
    public function toPush(object $notifiable): array
    {
        return ['title' => 'Credits running low', 'body' => $this->percent().'% of your monthly Credits left — scheduled posts stop when they run out.', 'url' => '/credits'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Credits running low')
            ->greeting('Hi '.$notifiable->name.',')
            ->line(number_format($this->left).' of '.number_format($this->monthly).' monthly Credits left. Scheduled posts and Comment-to-DM stop when Credits run out.')
            ->action('Top up Credits', config('app.frontend_url').'/credits')
            ->line('Top-up Credits never expire. Your monthly Credits reset at the start of the next cycle.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => NotificationType::CreditsLow->value,
            'left' => $this->left,
            'monthly' => $this->monthly,
            'percent' => $this->percent(),
        ];
    }

    public function percent(): int
    {
        return (int) max(0, min(100, floor($this->left / $this->monthly * 100)));
    }
}
