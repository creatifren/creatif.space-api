<?php

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The buyer's receipt-plus-goods. Sent to the Client from the webhook —
 * only once the money really arrived, never from the browser coming back.
 *
 * Clients have no dashboard bell, so this is mail-only. The delivery link
 * comes from the offer's `details.source_ref` — the file, Drive link or
 * private Space the editor's "What buyers receive" field stored. An offer
 * without one still gets the receipt; the seller arranges the handover.
 */
class OrderDelivered extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Order $order) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $rupiah = fn (int $n) => 'Rp'.number_format($n, 0, ',', '.');
        $what = $this->order->offerTitle();
        $link = $this->order->deliveryUrl();

        $mail = (new MailMessage)
            ->subject('Your purchase: '.$what)
            ->greeting('Hi '.$notifiable->displayName().',')
            ->line('You paid '.$rupiah($this->order->amount).' for '.$what
                .' from '.$this->order->creator->name.'.');

        if ($link !== null) {
            $mail->line('Your download is ready:')
                ->action('Open your purchase', $link);
        } else {
            $mail->line('The seller will be in touch about the handover — reply to this email if anything is unclear.');
        }

        return $mail->line('This link also lives under Purchases whenever you sign in with this Google account.');
    }
}
