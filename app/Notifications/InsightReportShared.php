<?php

namespace App\Notifications;

use App\Models\InsightShareLink;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * To the recipient of an Insight share link: one email with the report
 * link. Same family as the approval emails (ApprovalMailFooter: slogan
 * and "Approved with Creatif Space →").
 */
class InsightReportShared extends Notification
{
    public function __construct(
        public InsightShareLink $link,
        public string $url,
        public string $senderName,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $period = $this->link->period()->label('en');

        return ApprovalMailFooter::on((new MailMessage)
            ->subject("{$this->senderName} shared an Insight report with you")
            ->line("{$this->senderName} shared their social media report for {$period} with you on creatif.space.")
            ->action('Open the report', $this->url)
            ->line('The link works until '.$this->link->expires_at->utc()->format('j M Y').'. It also appears under "Reports shared with you" when you sign in with this email.'));
    }
}
