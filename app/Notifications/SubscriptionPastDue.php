<?php

namespace App\Notifications;

use App\Enums\NotificationType;
use App\Models\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The payment didn't happen and the clock has started. Deliberately not
 * silenceable: losing a plan without being warned would be the product
 * breaking a promise.
 */
class SubscriptionPastDue extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Subscription $subscription) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return NotificationChannels::for($notifiable, NotificationType::BillingReminder);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $plan = $this->subscription->plan->name;
        $until = $this->subscription->grace_ends_at?->translatedFormat('j F Y');

        return (new MailMessage)
            ->subject('Your '.$plan.' plan needs renewing')
            ->greeting('Hi '.$notifiable->name.',')
            ->line("The {$plan} period has ended and the renewal hasn’t gone through yet.")
            ->line($until
                ? "You keep everything until {$until}. After that the account goes back to Free."
                : 'The account will go back to Free shortly.')
            ->action('Renew now', config('app.frontend_url').'/settings?section=subscription')
            ->line('Nothing is deleted either way — Spaces beyond the Free limit simply become readable archives.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => NotificationType::BillingReminder->value,
            'plan' => $this->subscription->plan->key->value,
            'plan_name' => $this->subscription->plan->name,
            'grace_ends_at' => $this->subscription->grace_ends_at?->toIso8601String(),
        ];
    }
}
