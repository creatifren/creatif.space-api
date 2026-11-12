<?php

namespace App\Notifications;

use App\Enums\NotificationType;
use App\Enums\SocialPostApproval;
use App\Models\SocialPost;
use App\Models\User;
use App\Support\WeeklyDigest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A scheduled post whose time passed and that has not gone out: still
 * waiting for an approval, or never confirmed as published. Same switch as
 * a failed post ("Post missed / failed", publish_failed), and the two
 * share one email per post (SocialPost::claimFailureNotice).
 */
class SocialPostMissed extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public SocialPost $post) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return NotificationChannels::for($notifiable, NotificationType::SocialPostFailed);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $caption = $this->caption();
        $when = $this->when($notifiable);

        return (new MailMessage)
            ->subject('Post missed its time: '.$caption)
            ->greeting('Hi '.$notifiable->name.',')
            ->line('Your post “'.$caption.'” was scheduled for '.$when.' and hasn’t gone out.')
            ->line($this->why())
            ->action('Open social posts', config('app.frontend_url').'/social?post='.$this->post->ulid)
            ->line('The post is still there — pick a new time or publish it now.');
    }

    /** Why it missed, in the user's words — never a vendor's. */
    public function why(): string
    {
        return match ($this->post->approval) {
            SocialPostApproval::Client => 'It was still waiting for your client’s approval.',
            SocialPostApproval::Awaiting => 'It was still waiting for your approval.',
            SocialPostApproval::Revision => 'Your client asked for changes, so it was held.',
            default => 'It wasn’t confirmed as published.',
        };
    }

    private function when(object $notifiable): string
    {
        $tz = $notifiable instanceof User ? WeeklyDigest::timezone($notifiable) : (string) config('app.timezone');
        $at = $this->post->scheduled_at;

        return $at === null ? 'its time' : $at->setTimezone($tz)->format('D j M, H:i').' ('.$tz.')';
    }

    /** The caption on one line — it lands in a subject, which must not break. */
    private function caption(): string
    {
        return str($this->post->caption)->squish()->limit(60)->toString();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => NotificationType::SocialPostFailed->value,
            'kind' => 'missed',
            'post_id' => $this->post->ulid,
            'caption' => $this->caption(),
            'reason' => $this->why(),
        ];
    }
}
