<?php

namespace App\Notifications;

use App\Enums\NotificationType;
use App\Models\SocialPost;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A social post that never made it out. Sent once, when the last target
 * reports back and none of them published — a scheduled post failing
 * silently is a post the user believes went live.
 */
class SocialPostFailed extends Notification implements ShouldQueue
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

        return (new MailMessage)
            ->subject('Post didn’t publish: '.$caption)
            ->greeting('Hi '.$notifiable->name.',')
            ->line('Your post “'.$caption.'” couldn’t be published to any of its accounts.')
            ->line(self::reason($this->post->fail_reason))
            ->action('Open social posts', config('app.frontend_url').'/social')
            ->line('The post is still there — you can edit and try again.');
    }

    /**
     * The reason as the user may read it: never the name of the service we
     * publish through. A reason that carries one (raw detail passed along)
     * gives way to a plain line.
     */
    public static function reason(?string $reason): string
    {
        if ($reason === null || trim($reason) === '' || preg_match(self::VENDORS, $reason) === 1) {
            return 'No reason was given.';
        }

        return $reason;
    }

    /** The publishing services behind the app — never named to a user. */
    public const VENDORS = '/out\s*stand|post\s*-?\s*for\s*-?\s*me|upload-?post|ayrshare/i';

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
            'post_id' => $this->post->ulid,
            'caption' => $this->caption(),
            'fail_reason' => self::reason($this->post->fail_reason),
        ];
    }
}
