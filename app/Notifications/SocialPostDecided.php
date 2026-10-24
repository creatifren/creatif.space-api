<?php

namespace App\Notifications;

use App\Enums\NotificationType;
use App\Models\SocialApprovalLink;
use App\Models\SocialPost;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A client acted on an approval link (RF-043): approved one or more posts,
 * asked for changes, or approved a post that could not go out.
 *
 * `blocked` ignores the switches, like StorageQuota: the client believes
 * the post is on its way, and silencing that would leave it held with
 * nobody knowing. The client only ever sees a neutral sentence; the real
 * reason (usually Credits) is written here, for the owner.
 */
class SocialPostDecided extends Notification implements ShouldQueue
{
    use Queueable;

    public const APPROVED = 'approved';

    public const REVISE = 'revise';

    public const BLOCKED = 'blocked';

    public function __construct(
        public SocialApprovalLink $link,
        public string $decision,
        public string $by,
        public int $count = 1,
        public ?SocialPost $post = null,
        public ?string $reason = null,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return $this->decision === self::BLOCKED
            ? ['mail', 'database']
            : NotificationChannels::for($notifiable, NotificationType::SocialPostDecision);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $caption = $this->caption();
        $subject = match ($this->decision) {
            self::APPROVED => $this->count === 1
                ? "{$this->by} approved “{$caption}”"
                : "{$this->by} approved {$this->count} posts",
            self::REVISE => "{$this->by} asked for changes".($caption ? " on “{$caption}”" : ''),
            default => "A post {$this->by} approved couldn’t go out",
        };

        $mail = (new MailMessage)
            ->subject($subject)
            ->greeting('Hi '.$notifiable->name.',')
            ->line($subject.'.');

        if ($this->reason !== null) {
            $mail->line($this->reason);
        }

        return $mail->action(
            'Open the approval link',
            config('app.frontend_url').'/post/approvals?link='.$this->link->ulid,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => NotificationType::SocialPostDecision->value,
            'decision' => $this->decision,
            'by' => $this->by,
            'count' => $this->count,
            'link_id' => $this->link->ulid,
            'link_title' => $this->link->title,
            'post_id' => $this->post?->ulid,
            'caption' => $this->caption(),
            'reason' => $this->reason,
        ];
    }

    private function caption(): ?string
    {
        return $this->post === null ? null : str($this->post->caption)->limit(60)->toString();
    }
}
