<?php

namespace App\Notifications;

use App\Enums\NotificationType;
use App\Models\Invoice;
use App\Models\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The receipt. Sent when money actually arrives — from the webhook, never
 * from the browser coming back.
 */
class SubscriptionStarted extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Subscription $subscription,
        public Invoice $invoice,
    ) {}

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
        $until = $this->subscription->current_period_end?->translatedFormat('j F Y');

        return (new MailMessage)
            ->subject('You’re on '.$plan)
            ->greeting('Hi '.$notifiable->name.',')
            ->line("Payment received — {$plan} is active".($until ? " until {$until}." : '.'))
            ->line('Invoice '.$this->invoice->number.' · Rp'.number_format($this->invoice->amount, 0, ',', '.'))
            ->action('See your subscription', config('app.frontend_url').'/settings?section=subscription')
            ->line('Every Space you already made stays exactly where it is.');
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
            'invoice_number' => $this->invoice->number,
            'amount' => $this->invoice->amount,
        ];
    }
}
