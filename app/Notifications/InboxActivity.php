<?php

namespace App\Notifications;

use App\Enums\NotificationType;
use Illuminate\Notifications\Notification;

/**
 * New DMs / comments arrived. Bell only — never email — and batched: the
 * InboxNotifier folds everything that arrives while the bell row is
 * unread and recent into that one row, rather than one row per message.
 * This class names the type stored on the row and builds its data.
 */
class InboxActivity extends Notification
{
    public function __construct(public int $messages = 0, public int $comments = 0, public int $conversations = 0) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return self::data($this->messages, $this->comments, $this->conversations);
    }

    /** @return array<string, mixed> */
    public static function data(int $messages, int $comments, int $conversations): array
    {
        $parts = [];
        if ($messages > 0) {
            $parts[] = $messages.' new message'.($messages === 1 ? '' : 's')
                .($conversations > 1 ? " in {$conversations} conversations" : '');
        }
        if ($comments > 0) {
            $parts[] = $comments.' new comment'.($comments === 1 ? '' : 's');
        }

        return [
            'type' => NotificationType::InboxActivity->value,
            'messages' => $messages,
            'comments' => $comments,
            'conversations' => $conversations,
            'line' => implode(' and ', $parts) ?: 'New activity in your Inbox',
            'url' => config('app.frontend_url').'/inbox',
        ];
    }
}
