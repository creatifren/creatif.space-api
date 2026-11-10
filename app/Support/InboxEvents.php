<?php

namespace App\Support;

use App\Contracts\SocialProvider;
use App\Models\SocialAccount;
use App\Models\SocialConversation;
use Illuminate\Support\Facades\Log;

/**
 * One Inbox webhook event filed: a thread started, a DM in or out, a read
 * receipt, a comment. Returns what became of it — `filed`,
 * `filed:matched_by_webhook_ref`, or `dropped:<reason>` — which the caller
 * stores on webhook_events.outcome.
 *
 * Shared by the webhook and by inbox:reprocess-webhooks, which replays
 * stored events quietly (no bell, no unread counts).
 */
class InboxEvents
{
    public function __construct(private readonly SocialProvider $provider, private readonly bool $live = true) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function apply(string $type, array $data): string
    {
        return match ($type) {
            'conversation' => $this->startConversation($data),
            'message' => $this->recordMessage($data),
            'message.read' => $this->readMessages($data),
            'comment' => $this->recordComment($data),
            default => 'ignored',
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function startConversation(array $data): string
    {
        [$account, $matchedBy] = InboxStore::resolveAccount(
            $this->provider,
            (string) ($data['social_account_id'] ?? ''),
            isset($data['network']) ? (string) $data['network'] : null,
        );

        if ($account !== null) {
            InboxStore::upsertConversation($account, $data);

            return self::filed($matchedBy);
        }

        return InboxStore::resolveConversation($this->provider, (string) ($data['id'] ?? ''), $data['network'] ?? null) === null
            ? 'dropped:unknown_conversation'
            : 'filed';
    }

    /**
     * A DM in or out, or the outcome of one of ours. message.received
     * names no account — the thread does, so an unknown thread is looked
     * up at the provider. A new inbound message rings the owner's bell.
     *
     * @param  array<string, mixed>  $data
     */
    private function recordMessage(array $data): string
    {
        $conversation = InboxStore::resolveConversation($this->provider, (string) ($data['conversation_id'] ?? ''), $data['network'] ?? null);

        if ($conversation === null) {
            return 'dropped:unknown_conversation';
        }

        if (($data['contact_id'] ?? null) !== null && $conversation->contact_id === null) {
            $conversation->contact_id = (string) $data['contact_id'];
        }

        [, $inbound] = InboxStore::upsertMessage($conversation, $data, countUnread: $this->live);

        if ($inbound) {
            // A DM arriving proves the account's grant includes messages.
            $conversation->account()->whereNull('messages_scope_at')->update(['messages_scope_at' => now()]);
            if ($this->live) {
                InboxNotifier::message($conversation->user, $conversation->ulid);
            }
        }

        return 'filed';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function readMessages(array $data): string
    {
        $conversation = SocialConversation::query()
            ->where('provider', $this->provider->name())
            ->where('provider_conversation_id', (string) ($data['conversation_id'] ?? ''))
            ->first();

        if ($conversation === null) {
            return 'dropped:unknown_conversation';
        }

        InboxStore::markSeen($conversation, $data['read_at'] ?? null);

        return 'filed';
    }

    /**
     * A comment on one of the account's posts. Our own replies come back
     * through here too and are dropped.
     *
     * @param  array<string, mixed>  $data
     */
    private function recordComment(array $data): string
    {
        [$account, $matchedBy] = InboxStore::resolveAccount(
            $this->provider,
            (string) ($data['account_id'] ?? ''),
            isset($data['network']) && $data['network'] !== '' ? (string) $data['network'] : null,
        );

        $reason = match (true) {
            $account === null => 'unknown_account',
            ! $account->supportsComments() => 'comments_unsupported',
            (string) ($data['id'] ?? '') === '' => 'no_comment_id',
            InboxStore::isOwnComment($account, $data) => 'own_comment',
            default => null,
        };

        if ($reason !== null) {
            Log::channel('webhooks')->warning('inbox.comment_dropped', [
                'provider' => $this->provider->name(),
                'account' => $data['account_id'] ?? null,
                'reason' => $reason,
            ]);

            return 'dropped:'.$reason;
        }

        /** @var SocialAccount $account */
        $comment = InboxStore::upsertComment($account, $data, (string) ($data['network'] ?? ''));

        if ($this->live && $comment !== null && $comment->wasRecentlyCreated) {
            InboxNotifier::comment($account->user);
        }

        return $comment === null ? 'dropped:not_filed' : self::filed($matchedBy);
    }

    private static function filed(?string $matchedBy): string
    {
        return $matchedBy === null ? 'filed' : 'filed:matched_by_'.$matchedBy;
    }
}
