<?php

namespace App\Notifications;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification as Notifier;

/**
 * Money moved in Creem in a way that needs a person: a dispute, a partial
 * refund, Credits already spent. Goes to staff, never to the customer.
 */
class BillingNeedsReview extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $eventType, public string $eventId, public string $reason) {}

    /** Every active staff member, once the webhook's transaction commits. */
    public static function staff(string $eventType, string $eventId, string $reason): void
    {
        DB::afterCommit(fn () => Notifier::send(
            User::query()->where('is_staff', true)->where('status', UserStatus::Active)->get(),
            new self($eventType, $eventId, $reason),
        ));
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Billing review: '.$this->eventType)
            ->line($this->reason)
            ->line('Creem event: '.$this->eventId)
            ->line('The raw payload is in webhook_events.');
    }
}
