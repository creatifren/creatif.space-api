<?php

namespace App\Contracts;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;

/**
 * The relay that delivers posts to the platforms. One API key means one
 * shared workspace for every user of ours, so tenancy is carried in an
 * external id (our user's ulid) and enforced on our side.
 *
 * The shapes in and out are ours, not any vendor's — Post for Me's came
 * first, so they read like it; every other provider translates.
 *
 * Account:  {id, platform, username?, profile_photo_url?,
 *            status: 'connected'|'disconnected', external_id?, access_token?}
 * Post body (createPost/updatePost/replaceTargets):
 *           {caption, social_accounts: list<id>, external_id (our post ulid),
 *            scheduled_at?: ISO 8601, media?: list<{url, thumbnail_url?}>,
 *            account_configurations?: list<{social_account_id,
 *            configuration: {caption?, placement?: 'reels'|'stories'}}>}
 * Result:   {id?, post_id, social_account_id, success: bool, error?,
 *            platform_data?: {url?}}
 * Feed item:{platform_post_id, caption, platform_url?, posted_at?,
 *            media: list, external_post_id?, provider_post_id?, metrics: array}
 * Webhook event: {type: 'account'|'account.lost'|'post'|'result', data}
 *            where data is an Account, {id?, external_id?, status?}, an
 *            account id ({id}), or a Result.
 *
 * Inbox (DMs and comments) — a relay without one throws InboxUnsupported:
 * Conversation: {id, social_account_id?, network, contact: {id?, username?,
 *            name?, avatar_url?}, last_message?: string, last_message_at?,
 *            last_inbound_at?, unread_count?: int, status?: string}
 * Message:  {id, conversation_id, direction: 'in'|'out', content?,
 *            media_urls: list<string>, status: 'pending'|'sent'|'failed',
 *            error?, source?: 'api'|'contact'|'native_app',
 *            platform_message_id?, scheduled_at?, sent_at?}
 * Comment:  {id, post_id?, platform_post_id?, parent_comment_id?,
 *            author_id?, author_username?, text?, created_at?, account_id?}
 * Inbox webhook events carry an `id` to deduplicate on and are one of
 *            'conversation' (Conversation), 'message' (Message, maybe
 *            partial: a status change), 'message.read' ({conversation_id,
 *            read_at?}), 'comment' (Comment, with network).
 */
interface SocialProvider
{
    /** The value stored in the `provider` column of the rows it owns. */
    public function name(): string;

    /**
     * Where the user goes to sign in and grant an account, tagged with
     * our user (or a claim tag).
     *
     * @param  array{handle?: string, app_password?: string}  $bluesky
     *
     * @throws ConnectionException
     */
    public function authUrl(string $platform, string $externalId, array $bluesky = []): string;

    /**
     * Every account tagged with this external id.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws ConnectionException
     */
    public function accountsFor(string $externalId): array;

    /**
     * One account, or null when it does not exist.
     *
     * @return array<string, mixed>|null
     *
     * @throws ConnectionException
     */
    public function account(string $accountId): ?array;

    /**
     * Whether `assignAccount` can move an account to another tag — the
     * claim flow parks an account under a one-off tag only where it can.
     */
    public function canReassign(): bool;

    /**
     * Re-tag an account with another external id.
     *
     * @throws ConnectionException
     */
    public function assignAccount(string $accountId, string $externalId): void;

    /**
     * Revoke the grant. A 404 is success.
     *
     * @throws ConnectionException
     */
    public function disconnect(string $accountId): void;

    /**
     * Remove the account from the workspace. A 404 is success.
     *
     * @throws ConnectionException
     */
    public function deleteAccount(string $accountId): void;

    /**
     * Create (and schedule) a post.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed> at least `id`; `status` 'processed' when already out
     *
     * @throws ConnectionException
     */
    public function createPost(array $payload): array;

    /**
     * Replace a post that has not gone out yet, same destinations.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws ConnectionException
     */
    public function updatePost(string $postId, array $payload): void;

    /**
     * Replace a post whose destinations changed. Returns the post id to
     * keep — a provider that cannot change destinations in place cancels
     * and re-creates, so the id may be new.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws ConnectionException
     */
    public function replaceTargets(string $postId, array $payload): string;

    /**
     * Cancel a post that has not gone out yet. A 404 is success.
     *
     * @throws ConnectionException
     */
    public function deletePost(string $postId): void;

    /**
     * Take a published post down from the networks that allow it. Answers
     * per destination (the relay's account id): removed, or why it stays.
     * A destination missing from the answer was not taken down.
     *
     * @return array<string, array{removed: bool, error: string|null}>
     *
     * @throws ConnectionException
     * @throws RequestException
     */
    public function deletePublished(string $postId): array;

    /**
     * Per-destination results for these posts, finished ones only.
     *
     * @param  list<string>  $postIds
     * @return array<int, array<string, mixed>>
     *
     * @throws ConnectionException
     */
    public function resultsFor(array $postIds): array;

    /**
     * Posts on the account with their metrics.
     *
     * @return array{data: array<int, array<string, mixed>>, cursor: string|null}
     *
     * @throws ConnectionException
     */
    public function feed(string $accountId, ?string $cursor = null, int $limit = 25): array;

    /** Is this delivery really from the provider? Unconfigured rejects everything. */
    public function verifyWebhook(Request $request): bool;

    /**
     * The delivery as our events, in order. Unknown events give none.
     *
     * @return list<array{type: string, data: array<string, mixed>}>
     */
    public function webhookEvents(Request $request): array;

    /** Whether this relay has an Inbox (DMs and comments) at all. */
    public function supportsInbox(): bool;

    /**
     * The account's follower (YouTube: subscriber) count, or null when the
     * network or relay does not report one.
     *
     * @throws ConnectionException
     */
    public function followersCount(string $accountId): ?int;

    /**
     * DM threads on an account, newest first.
     *
     * @return array{data: list<array<string, mixed>>, cursor: string|null}
     *
     * @throws ConnectionException
     */
    public function conversations(string $accountId, ?string $cursor = null, int $limit = 50): array;

    /**
     * One thread, or null when it does not exist.
     *
     * @return array<string, mixed>|null
     *
     * @throws ConnectionException
     */
    public function conversation(string $conversationId): ?array;

    /**
     * Messages in a thread, newest first.
     *
     * @return array{data: list<array<string, mixed>>, cursor: string|null}
     *
     * @throws ConnectionException
     */
    public function messages(string $conversationId, ?string $cursor = null, int $limit = 50): array;

    /**
     * Send a DM. Accepted, not delivered: the result is `pending` until a
     * webhook says otherwise.
     *
     * @param  array{content?: string|null, media_urls?: list<string>, scheduled_at?: string|null}  $message
     * @return array<string, mixed> a Message
     *
     * @throws ConnectionException
     */
    public function sendMessage(string $conversationId, array $message): array;

    /**
     * Take back a scheduled DM that has not gone out. A 404 is success.
     *
     * @throws ConnectionException
     */
    public function deleteScheduledMessage(string $conversationId, string $messageId): void;

    /** @throws ConnectionException */
    public function markConversationRead(string $conversationId): void;

    /**
     * The one private DM reply a public comment allows (Instagram, within
     * 7 days, text only).
     *
     * @return array<string, mixed> {conversation_id?: string, message_id?: string}
     *
     * @throws ConnectionException
     */
    public function privateReply(string $accountId, string $commentId, ?string $authorId, string $content): array;

    /**
     * Comments on one post of an account.
     *
     * @return list<array<string, mixed>>
     *
     * @throws ConnectionException
     */
    public function comments(string $postId, string $network, string $accountUsername): array;

    /**
     * Reply publicly, threaded under a comment.
     *
     * @return array<string, mixed> {id?: string}
     *
     * @throws ConnectionException
     */
    public function replyToComment(string $postId, string $commentId, string $content, string $accountUsername): array;

    /**
     * A Pinterest account's boards — a Pin needs one. Empty where the relay
     * cannot list them.
     *
     * @return list<array{id: string, name: string}>
     *
     * @throws ConnectionException
     */
    public function pinterestBoards(string $accountId): array;
}
