<?php

namespace App\Notifications;

use App\Enums\InvoiceStatus;
use App\Enums\NotificationType;
use App\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The payment did not go through.
 *
 * `NotificationType::BillingFailed` was declared in Fase 5 and never raised:
 * invoices really did transition to Failed in the webhook, and nobody was
 * told. Payment failure is the one moment where silence costs the most —
 * the plan is on a clock from here, and the fix is thirty seconds of the
 * owner's time if they know about it.
 *
 * Not silenceable, for the same reason SubscriptionPastDue is not: it is
 * absent from NotificationType::switchable(), so Settings never offers a
 * switch and `wants()` answers true. Losing a plan without being told would
 * be the product breaking a promise about money.
 */
class InvoiceFailed extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Invoice $invoice) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return NotificationChannels::for($notifiable, NotificationType::BillingFailed);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $plan = $this->invoice->subscription->plan->name;
        $amount = 'Rp'.number_format((float) $this->invoice->amount, 0, ',', '.');

        /*
         * Expired and failed are different news. Expired means the payment
         * window closed untouched — usually "I meant to and forgot". Failed
         * means the bank or wallet refused it, which may need a different
         * card. Saying the right one saves the reader a guess.
         */
        $expired = $this->invoice->status === InvoiceStatus::Expired;

        $message = (new MailMessage)
            ->subject($expired
                ? 'Your '.$plan.' payment window closed'
                : 'Your '.$plan.' payment did not go through')
            ->greeting('Hi '.$notifiable->name.',')
            ->line($expired
                ? "The payment for your {$plan} plan ({$amount}) wasn’t completed in time, so the bill has expired."
                : "The payment for your {$plan} plan ({$amount}) was declined.");

        if (! $expired) {
            $message->line('This is usually the bank or e-wallet rather than anything on your side — a second attempt, or a different method, normally goes through.');
        }

        return $message
            ->action('Try again', config('app.frontend_url').'/settings?section=subscription')
            ->line('Your Spaces and files are untouched. Nothing is deleted if the plan lapses — Spaces beyond the Free limit simply become readable archives.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => NotificationType::BillingFailed->value,
            'plan' => $this->invoice->subscription->plan->key->value,
            'plan_name' => $this->invoice->subscription->plan->name,
            'invoice_number' => $this->invoice->number,
            'amount' => $this->invoice->amount,
            'status' => $this->invoice->status->value,
        ];
    }
}
