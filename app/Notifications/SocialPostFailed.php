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
        $caption = str($this->post->caption)->limit(60);

        return (new MailMessage)
            ->subject('Post didn’t publish: '.$caption)
            ->greeting('Hi '.$notifiable->name.',')
            ->line('Your post “'.$caption.'” couldn’t be published to any of its accounts.')
            ->line($this->post->fail_reason ?? 'The provider didn’t say why.')
            ->action('Open social posts', config('app.frontend_url').'/social')
            ->line('The post is still there — you can edit and try again.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => NotificationType::SocialPostFailed->value,
            'post_id' => $this->post->ulid,
            'caption' => str($this->post->caption)->limit(60)->toString(),
            'fail_reason' => $this->post->fail_reason,
        ];
    }
}
