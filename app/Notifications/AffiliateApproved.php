<?php

namespace App\Notifications;

use App\Enums\NotificationType;
use App\Models\Affiliate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "You're in." Carries the link, which is the thing the person applied
 * for — an approval email without it would just be an errand.
 */
class AffiliateApproved extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Affiliate $affiliate) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return NotificationChannels::for($notifiable, NotificationType::ProductNews);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $link = config('app.frontend_url').'/?ref='.$this->affiliate->code;

        return (new MailMessage)
            ->subject('You’re in — here’s your referral link')
            ->greeting('Hi '.$notifiable->name.',')
            ->line('Your application to the Creatif Space affiliate program was approved.')
            ->line('Your link: '.$link)
            ->line('You earn '.(int) $this->affiliate->tier_percent
                .'% of every payment your referrals make, for twelve months.')
            ->action('Open your dashboard', config('app.frontend_url').'/referral')
            ->line('Commission is held 30 days before it can be withdrawn, and pays out in twelve monthly parts.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => NotificationType::ProductNews->value,
            'affiliate_id' => $this->affiliate->ulid,
            'code' => $this->affiliate->code,
        ];
    }
}
