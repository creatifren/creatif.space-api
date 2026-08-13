<?php

namespace App\Notifications;

use App\Enums\NotificationType;
use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Someone bought something. Sent from the webhook, when the money is
 * actually in the balance — never from the buyer's browser coming back.
 */
class OrderPaid extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Order $order) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return NotificationChannels::for($notifiable, NotificationType::OrderPaid);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $rupiah = fn (int $n) => 'Rp'.number_format($n, 0, ',', '.');
        $what = $this->order->offerTitle();

        return (new MailMessage)
            ->subject('Sold: '.$what)
            ->greeting('Hi '.$notifiable->name.',')
            ->line($this->order->client->displayName().' paid '.$rupiah($this->order->amount).' for '.$what.'.')
            ->line('Creatif Space fee '.$this->order->fee_percent.'% — '.$rupiah($this->order->fee_amount).'.')
            ->line($rupiah($this->order->net_amount).' has been added to your balance.')
            ->action('See your earnings', config('app.frontend_url').'/insights?tab=orders')
            ->line('Withdraw any time from Rp50.000 — it lands in about two business days.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => NotificationType::OrderPaid->value,
            'order_id' => $this->order->ulid,
            'title' => $this->order->offerTitle(),
            'buyer_name' => $this->order->client->displayName(),
            'amount' => $this->order->amount,
            'net_amount' => $this->order->net_amount,
        ];
    }
}
