<?php

namespace App\Notifications;

use App\Models\SocialApprovalLink;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The one reminder to a Content Plan's approver: posts are still waiting
 * and the first goes out in about a day. Same approval footer as the rest
 * of the approval mails; one click turns reminders off for this plan.
 */
class ContentPlanReminder extends Notification
{
    public function __construct(
        public SocialApprovalLink $link,
        public int $waiting,
        public CarbonImmutable $firstAt,
        public string $optOutUrl,
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
        $editor = $this->link->user->name;
        $plan = $this->link->title !== null && $this->link->title !== '' ? "“{$this->link->title}”" : 'the Content Plan';
        $tz = $this->link->scope_timezone ?: (string) config('app.timezone');
        $when = $this->firstAt->setTimezone($tz)->format('D j M, H:i').' ('.$tz.')';
        $posts = $this->waiting === 1 ? '1 post is' : "{$this->waiting} posts are";

        return ApprovalMailFooter::on((new MailMessage)
            ->subject("Reminder: {$editor}’s posts are waiting for your approval")
            ->line("{$posts} still waiting for your decision on {$plan} from {$editor}.")
            ->line("The first one is scheduled for {$when}.")
            ->action('Review the plan', $this->link->url())
            ->line('This is the only reminder for this plan.')
            ->line("Don’t want reminders for this plan? [Turn them off]({$this->optOutUrl})."));
    }
}
