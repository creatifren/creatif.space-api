<?php

namespace App\Support;

use App\Enums\NotificationType;
use App\Models\User;
use App\Notifications\InboxActivity;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The bell for the Inbox. One unread row per window: a busy hour of DMs is
 * one "12 new messages in 3 conversations", not twelve rows. Once the user
 * reads it (or the window passes) the next arrival starts a new row.
 * Honours the bell switch if the user has one for this type; never mails.
 */
class InboxNotifier
{
    /** How long one bell row keeps absorbing new arrivals. */
    public const WINDOW_MINUTES = 60;

    public static function message(User $owner, string $conversationId): void
    {
        self::bump($owner, 'messages', $conversationId);
    }

    public static function comment(User $owner): void
    {
        self::bump($owner, 'comments', null);
    }

    private static function bump(User $owner, string $kind, ?string $conversationId): void
    {
        if (! $owner->wants(NotificationType::InboxActivity, 'bell')) {
            return;
        }

        DB::transaction(function () use ($owner, $kind, $conversationId) {
            /** @var DatabaseNotification|null $row */
            $row = $owner->notifications()
                ->where('type', InboxActivity::class)
                ->whereNull('read_at')
                ->where('created_at', '>=', now()->subMinutes(self::WINDOW_MINUTES))
                ->lockForUpdate()
                ->latest()
                ->first();

            $data = (array) ($row->data ?? []);
            $threads = array_values(array_unique(array_merge(
                (array) ($data['conversation_ids'] ?? []),
                $conversationId === null ? [] : [$conversationId],
            )));
            $messages = (int) ($data['messages'] ?? 0) + ($kind === 'messages' ? 1 : 0);
            $comments = (int) ($data['comments'] ?? 0) + ($kind === 'comments' ? 1 : 0);
            $new = InboxActivity::data($messages, $comments, count($threads)) + ['conversation_ids' => $threads];

            if ($row === null) {
                $owner->notifications()->create([
                    'id' => (string) Str::uuid(),
                    'type' => InboxActivity::class,
                    'data' => $new,
                ]);

                return;
            }

            $row->forceFill(['data' => $new])->save();
        });
    }
}
