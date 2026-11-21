<?php

namespace App\Notifications;

use App\Enums\NotificationType;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** v10.5: a post's Auto DM paused — not enough Credits for the next DM. Bell (always on: money). */
class AutoDmPaused extends Notification
{
    use Queueable;

    public function __construct(public string $postId, public int $needed) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => NotificationType::AutoDmPaused->value,
            'post_id' => $this->postId,
            'needed' => $this->needed,
        ];
    }
}
