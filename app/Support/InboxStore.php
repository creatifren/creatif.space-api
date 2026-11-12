<?php

namespace App\Support;

use App\Contracts\SocialProvider;
use App\Enums\SocialAccountStatus;
use App\Enums\SocialPlatform;
use App\Models\SocialAccount;
use App\Models\SocialComment;
use App\Models\SocialConversation;
use App\Models\SocialMessage;
use App\Models\SocialPost;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Where Inbox data lands, from a webhook or a backfill alike. Everything
 * is an upsert on the provider's ids, so a webhook, its redelivery and the
 * hourly sync of the same message end in one row. Tenancy comes from the
 * account the thing belongs to — never from the payload's org id, which
 * is our one shared Outstand organization.
 */
class InboxStore
{
    /** Our own public replies, remembered so their comment.received is skipped. */
    public const OWN_COMMENT_KEY = 'inbox-own-comment:';

    /** How long a provider account's webhook reference id is remembered. */
    private const REMOTE_ACCOUNT_TTL_HOURS = 24;

    /**
     * The account an inbox event names, resolved as far as is safe:
     *
     *  1. our row for that provider account id — or, when that row was left
     *     disconnected by a reconnect, its connected twin (network_unique_id);
     *  2. an Outstand account we never stored (an older connect of the same
     *     IG/FB account, purged here): asked for at the provider, then
     *     matched to the ONE connected row on the same platform with the
     *     same network_webhook_reference_id — the id Meta's webhooks carry.
     *     Never across platforms; none or several candidates → null.
     *
     * @return array{0: SocialAccount|null, 1: string|null} the account and how
     *                                                      it was matched ('webhook_ref' for 2.)
     */
    public static function resolveAccount(SocialProvider $provider, string $providerAccountId, ?string $network = null): array
    {
        $account = self::account($provider->name(), $providerAccountId);

        if ($account !== null) {
            if (! $account->isUsable()) {
                $account = self::liveTwin($account) ?? $account;
            }

            // A disconnected channel takes nothing new into Engage (P1):
            // its data was deleted and must not grow back from a webhook.
            if ($account->status === SocialAccountStatus::Disconnected) {
                return [null, null];
            }

            return [$account, null];
        }

        if ($providerAccountId === '') {
            return [null, null];
        }

        $remote = self::remoteAccount($provider, $providerAccountId);
        $ref = is_string($remote['network_webhook_reference_id'] ?? null) ? $remote['network_webhook_reference_id'] : null;
        $platform = SocialPlatform::tryFrom((string) ($remote['platform'] ?? $network ?? ''));

        if ($ref === null || $ref === '' || $platform === null) {
            return [null, null];
        }

        $candidates = SocialAccount::query()
            ->where('provider', $provider->name())
            ->where('platform', $platform)
            ->where('network_webhook_reference_id', $ref)
            ->where('status', SocialAccountStatus::Connected)
            ->limit(2)
            ->get();

        return $candidates->count() === 1 ? [$candidates->first(), 'webhook_ref'] : [null, null];
    }

    /**
     * A provider account as the provider describes it, remembered for a day
     * (one lookup per unknown account, not per webhook). A failed lookup is
     * not remembered.
     *
     * @return array<string, mixed>|null
     */
    private static function remoteAccount(SocialProvider $provider, string $providerAccountId): ?array
    {
        $key = 'provider-account:'.$provider->name().':'.$providerAccountId;
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }

        try {
            $remote = $provider->account($providerAccountId);
        } catch (Throwable) {
            return null;
        }

        if (is_array($remote)) {
            Cache::put($key, $remote, now()->addHours(self::REMOTE_ACCOUNT_TTL_HOURS));
        }

        return $remote;
    }

    /**
     * The connected row for the same network account (same platform and
     * network_unique_id), when this one is not — a reconnect leaves the old
     * row behind under a different provider account id.
     */
    public static function liveTwin(SocialAccount $account): ?SocialAccount
    {
        if ($account->network_unique_id === null) {
            return null;
        }

        return SocialAccount::query()
            ->where('provider', $account->provider)
            ->where('platform', $account->platform)
            ->where('network_unique_id', $account->network_unique_id)
            ->whereKeyNot($account->id)
            ->where('status', SocialAccountStatus::Connected)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * The local account for a provider account id. The same platform
     * account can sit under two of our users (one Disconnected): the
     * connected one wins.
     */
    public static function account(string $provider, string $providerAccountId): ?SocialAccount
    {
        if ($providerAccountId === '') {
            return null;
        }

        return SocialAccount::query()
            ->where('provider', $provider)
            ->where('provider_account_id', $providerAccountId)
            ->orderByRaw('case when status = ? then 0 else 1 end', [SocialAccountStatus::Connected->value])
            ->orderByDesc('id')
            ->first();
    }

    /**
     * The local thread for a provider conversation id; unknown ones are
     * looked up at the provider (a webhook can beat the backfill) and
     * filed under the account they belong to. Null when nobody of ours
     * holds it.
     */
    public static function resolveConversation(SocialProvider $provider, string $conversationId, ?string $network = null): ?SocialConversation
    {
        if ($conversationId === '') {
            return null;
        }

        $local = SocialConversation::query()
            ->where('provider', $provider->name())
            ->where('provider_conversation_id', $conversationId)
            ->first();

        if ($local !== null) {
            return $local;
        }

        try {
            $remote = $provider->conversation($conversationId);
        } catch (Throwable) {
            return null;
        }

        $account = $remote === null ? null : self::resolveAccount($provider, (string) ($remote['social_account_id'] ?? ''), $network)[0];

        return $account === null ? null : self::upsertConversation($account, $remote + ['network' => $network ?? 'instagram']);
    }

    /**
     * @param  array<string, mixed>  $c  a provider Conversation
     */
    public static function upsertConversation(SocialAccount $account, array $c): SocialConversation
    {
        $contact = (array) ($c['contact'] ?? []);
        $conversation = SocialConversation::query()->firstOrNew([
            'provider' => $account->provider,
            'provider_conversation_id' => (string) $c['id'],
        ]);

        $conversation->fill(array_filter([
            'user_id' => $account->user_id,
            'social_account_id' => $account->id,
            'network' => (string) ($c['network'] ?? $account->platform->value),
            'contact_id' => $contact['id'] ?? null,
            'contact_username' => isset($contact['username']) ? ProviderFields::text(ltrim((string) $contact['username'], '@')) : null,
            'contact_name' => ProviderFields::text($contact['name'] ?? null),
            'contact_avatar_url' => $contact['avatar_url'] ?? null,
            'last_message_preview' => isset($c['last_message']) ? str((string) $c['last_message'])->limit(270)->toString() : null,
        ], fn ($v) => $v !== null));

        foreach (['last_message_at', 'last_inbound_at'] as $field) {
            $at = self::time($c[$field] ?? null);
            if ($at !== null && ($conversation->{$field} === null || $at->greaterThan($conversation->{$field}))) {
                $conversation->{$field} = $at;
            }
        }

        if (isset($c['unread_count']) && is_numeric($c['unread_count'])) {
            $conversation->unread_count = max(0, (int) $c['unread_count']);
        }

        $conversation->status ??= SocialConversation::OPEN;
        // The list is ordered (and cursor-paged) on this; never leave it null.
        $conversation->last_message_at ??= now();
        $conversation->save();

        return $conversation;
    }

    /**
     * File one message. A partial (a status change from message.sent /
     * message.failed) updates the row it names, or creates one when it was
     * sent outside us (native app). Returns the row and whether it is a new
     * inbound message — the caller decides whether that is news.
     *
     * @param  array<string, mixed>  $m  a provider Message
     * @return array{0: SocialMessage|null, 1: bool}
     */
    public static function upsertMessage(SocialConversation $conversation, array $m, bool $countUnread): array
    {
        $id = (string) ($m['id'] ?? '');
        if ($id === '') {
            return [null, false];
        }

        $message = SocialMessage::query()->firstOrNew([
            'social_conversation_id' => $conversation->id,
            'provider_message_id' => $id,
        ]);
        $new = ! $message->exists;

        // A late "pending" (the backfill racing the webhook) never undoes a result.
        $status = $m['status'] ?? null;
        if ($status === SocialMessage::PENDING && in_array($message->status, [SocialMessage::SENT, SocialMessage::FAILED], true) && ! $new) {
            $status = null;
        }

        $message->fill(array_filter([
            'direction' => $new ? (($m['direction'] ?? 'in') === 'out' ? SocialMessage::OUT : SocialMessage::IN) : null,
            'content' => $m['content'] ?? null,
            'media_urls' => ($m['media_urls'] ?? []) ?: null,
            'status' => $status,
            'error' => $m['error'] ?? null,
            'source' => $m['source'] ?? null,
            'platform_message_id' => $m['platform_message_id'] ?? null,
            'scheduled_at' => self::time($m['scheduled_at'] ?? null),
            'sent_at' => self::time($m['sent_at'] ?? null)
                ?? self::time($m['event_at'] ?? null)
                ?? self::time($m['received_at'] ?? null),
        ], fn ($v) => $v !== null));
        if ($status === SocialMessage::SENT) {
            $message->error = null;
        }
        $message->direction ??= SocialMessage::IN;
        $message->status ??= SocialMessage::SENT;
        if ($new && $message->sent_at === null && $message->status === SocialMessage::SENT) {
            $message->sent_at = now();
        }
        $message->save();

        $inbound = $new && $message->direction === SocialMessage::IN;
        $at = $message->sent_at ?? $message->created_at;

        if ($message->status !== SocialMessage::FAILED && ($conversation->last_message_at === null || $at->greaterThanOrEqualTo($conversation->last_message_at))) {
            $conversation->last_message_at = $at;
            if ($message->content !== null) {
                $conversation->last_message_preview = str($message->content)->limit(270)->toString();
            }
        }

        if ($message->direction === SocialMessage::IN && ($conversation->last_inbound_at === null || $at->greaterThan($conversation->last_inbound_at))) {
            $conversation->last_inbound_at = $at;
        }

        if ($inbound && $countUnread) {
            $conversation->unread_count++;
            // A thread marked done comes back when the contact writes again.
            $conversation->status = SocialConversation::OPEN;
        }

        $conversation->save();

        return [$message, $inbound];
    }

    /** The contact read our messages up to `$at` (default: now). */
    public static function markSeen(SocialConversation $conversation, mixed $at = null): void
    {
        $at = self::time($at) ?? now();

        $conversation->messages()
            ->where('direction', SocialMessage::OUT)
            ->whereNull('read_at')
            ->where(fn ($q) => $q->whereNull('sent_at')->orWhere('sent_at', '<=', $at))
            ->update(['read_at' => $at]);
    }

    /**
     * File a comment on one of the account's posts. Our own (the account
     * answering, or a reply we posted) is not inbox material: null.
     *
     * @param  array<string, mixed>  $c  a provider Comment
     */
    public static function upsertComment(SocialAccount $account, array $c, string $network): ?SocialComment
    {
        $id = (string) ($c['id'] ?? '');
        if ($id === '' || self::isOwnComment($account, $c)) {
            return null;
        }

        $postId = isset($c['post_id']) ? (string) $c['post_id'] : null;
        $post = $postId === null ? null : SocialPost::query()
            ->where('provider', $account->provider)
            ->where('provider_post_id', $postId)
            ->value('id');

        $comment = SocialComment::query()->firstOrNew([
            'social_account_id' => $account->id,
            'comment_id' => $id,
        ]);

        $comment->fill(array_filter([
            'user_id' => $account->user_id,
            'social_post_id' => $post,
            'provider' => $account->provider,
            'provider_post_id' => $postId,
            'platform_post_id' => $c['platform_post_id'] ?? null,
            'parent_comment_id' => $c['parent_comment_id'] ?? null,
            'author_id' => isset($c['author_id']) ? (string) $c['author_id'] : null,
            'author_username' => isset($c['author_username']) ? ProviderFields::text(ltrim((string) $c['author_username'], '@')) : null,
            'text' => $c['text'] ?? null,
            'network' => $network !== '' ? $network : $account->platform->value,
            // The comment's own time; else the event's (provider envelope);
            // else when the webhook arrived (a replay passes the stored
            // receipt time) — never the moment of a later replay.
            'commented_at' => self::time($c['created_at'] ?? null)
                ?? self::time($c['event_at'] ?? null)
                ?? self::time($c['received_at'] ?? null),
        ], fn ($v) => $v !== null));
        $comment->commented_at ??= now();
        $comment->save();

        return $comment;
    }

    /** Remember a reply we posted, so its own webhook is not news. */
    public static function rememberOwnComment(?string $id): void
    {
        if (is_string($id) && $id !== '') {
            Cache::put(self::OWN_COMMENT_KEY.$id, true, now()->addDays(7));
        }
    }

    /**
     * @param  array<string, mixed>  $c
     */
    public static function isOwnComment(SocialAccount $account, array $c): bool
    {
        if (Cache::has(self::OWN_COMMENT_KEY.$c['id'])) {
            return true;
        }

        $mine = strtolower(ltrim((string) $account->username, '@'));
        $author = strtolower(ltrim((string) ($c['author_username'] ?? ''), '@'));

        return ($mine !== '' && $author === $mine)
            || (isset($c['author_id']) && (string) $c['author_id'] === $account->provider_account_id);
    }

    private static function time(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            $at = is_numeric($value)
                ? CarbonImmutable::createFromTimestamp((int) $value > 1_000_000_000_000 ? intdiv((int) $value, 1000) : (int) $value)
                : CarbonImmutable::parse((string) $value);

            // Eloquent writes a date's wall clock without converting it, and
            // reads it back in the app timezone (Asia/Jakarta). A provider's
            // "…Z" time must be moved there first, or it lands 7h early.
            return $at->setTimezone((string) config('app.timezone'));
        } catch (Throwable) {
            return null;
        }
    }
}
