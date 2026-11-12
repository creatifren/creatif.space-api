<?php

namespace App\Support;

use App\Enums\SocialAccountStatus;
use App\Models\SocialAccount;
use App\Models\SocialComment;
use App\Models\SocialConversation;
use App\Models\SocialMessage;
use Illuminate\Support\Facades\DB;

/**
 * Engage data of a disconnected channel (P1 privacy, 13 Nov): its DMs,
 * their messages and its comments are never listed or read once the
 * channel is disconnected (Inbox::onLiveChannel), and are deleted here.
 *
 * Retention: none. They are deleted as soon as the channel becomes
 * disconnected — by the user, by the relay or by account deletion — and
 * the daily `inbox:purge-disconnected` sweep removes any left behind (a
 * bulk status update, a webhook that raced the disconnect). The platform
 * keeps the originals; creatif.space keeps no copy.
 *
 * Reconnecting starts the channel's Engage from scratch: inbox_synced_at
 * is cleared, so the first sync after the reconnect reads what the
 * platform returns under the new connection. Nothing stored before the
 * disconnect comes back.
 */
final class InboxPurge
{
    /** @return array{conversations: int, messages: int, comments: int} */
    public static function account(SocialAccount $account): array
    {
        return DB::transaction(function () use ($account): array {
            $ids = SocialConversation::query()->where('social_account_id', $account->id)->pluck('id');
            $comments = SocialComment::query()->where('social_account_id', $account->id)->delete();
            $messages = $ids->isEmpty() ? 0 : SocialMessage::query()->whereIn('social_conversation_id', $ids)->delete();
            $conversations = $ids->isEmpty() ? 0 : SocialConversation::query()->whereKey($ids)->delete();
            $account->forceFill(['inbox_synced_at' => null])->saveQuietly();

            return ['conversations' => $conversations, 'messages' => $messages, 'comments' => $comments];
        });
    }

    /**
     * Every disconnected channel that still holds Engage data.
     *
     * @return array{accounts: int, conversations: int, messages: int, comments: int}
     */
    public static function disconnected(): array
    {
        $total = ['accounts' => 0, 'conversations' => 0, 'messages' => 0, 'comments' => 0];

        SocialAccount::query()
            ->where('status', SocialAccountStatus::Disconnected)
            ->where(fn ($q) => $q
                ->whereExists(fn ($e) => $e->select(DB::raw(1))->from('social_conversations')->whereColumn('social_conversations.social_account_id', 'social_accounts.id'))
                ->orWhereExists(fn ($e) => $e->select(DB::raw(1))->from('social_comments')->whereColumn('social_comments.social_account_id', 'social_accounts.id')))
            ->each(function (SocialAccount $account) use (&$total): void {
                $done = self::account($account);
                $total['accounts']++;
                foreach ($done as $k => $n) {
                    $total[$k] += $n;
                }
            });

        return $total;
    }
}
