<?php

namespace App\Services;

use App\Contracts\SocialProvider;
use App\Exceptions\InboxUnsupported;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Post for Me, by hand — the relay that delivers posts to the nine
 * platforms. One API key means one shared workspace for every user of
 * ours, so tenancy is carried in `external_id` (our user's ulid) and
 * enforced on our side before any call is made.
 */
class PostForMeService implements SocialProvider
{
    public function name(): string
    {
        return 'postforme';
    }

    /** One external_id per platform account, and it can be PATCHed. */
    public function canReassign(): bool
    {
        return true;
    }

    /**
     * Auth URL the user is redirected to in order to sign in on the
     * platform and grant the account. `external_id` tags the resulting
     * account with our user, which is what sync and webhooks key on.
     *
     * @param  array{handle?: string, app_password?: string}  $bluesky
     *
     * @throws ConnectionException
     */
    public function authUrl(string $platform, string $externalId, array $bluesky = []): string
    {
        // Where the browser lands after granting is the PROJECT's redirect
        // URL, set once in the Post for Me dashboard — point it at
        // {frontend}/social/connect?social=connected. Quickstart projects
        // reject a per-request redirect_url_override outright (400), so it
        // is deliberately not sent here.
        $payload = [
            'platform' => $platform,
            'external_id' => $externalId,
            // Always show the sign-in / account picker. Instagram (Business
            // Login) and X default to false: a second connect silently reused
            // the account the browser was already signed in to, so the
            // "new" account came back as the old one and nothing was added.
            'force_reauth' => true,
        ];

        // Two platforms take a connection_type. Instagram signs in on
        // Instagram itself (Business Login for Instagram), not through a
        // Facebook Page — the owner's call, 2026-10-06. LinkedIn must be
        // "organization" on the provider's system credentials.
        if ($platform === 'instagram') {
            $payload['platform_data'] = ['instagram' => ['connection_type' => 'instagram']];
        } elseif ($platform === 'linkedin') {
            $payload['platform_data'] = ['linkedin' => ['connection_type' => 'organization']];
        } elseif ($platform === 'bluesky') {
            // Required by the provider: without them the request is rejected.
            $payload['platform_data'] = ['bluesky' => $bluesky];
        }

        $response = $this->client()->post('/social-accounts/auth-url', $payload);

        $response->throw();

        $url = $response->json('url');

        if (! is_string($url) || $url === '') {
            throw new RuntimeException('Post for Me returned no auth URL.');
        }

        return $url;
    }

    /**
     * Every provider account tagged with this external id — i.e. this
     * user's accounts, and nobody else's.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws ConnectionException
     */
    public function accountsFor(string $externalId): array
    {
        $response = $this->client()->get('/social-accounts', [
            'external_id' => $externalId,
            'limit' => 100,
        ]);

        $response->throw();

        return (array) $response->json('data', []);
    }

    /**
     * One provider account, or null when it does not exist.
     *
     * @return array<string, mixed>|null
     *
     * @throws ConnectionException
     */
    public function account(string $accountId): ?array
    {
        $response = $this->client()->get('/social-accounts/'.rawurlencode($accountId));

        if ($response->status() === 404) {
            return null;
        }

        $response->throw();

        return (array) $response->json();
    }

    /**
     * Re-tag an account with another of our users. The provider keeps one
     * external_id per platform account, so this moves it — the old owner
     * stops seeing it.
     *
     * @throws ConnectionException
     */
    public function assignAccount(string $accountId, string $externalId): void
    {
        $this->client()
            ->patch('/social-accounts/'.rawurlencode($accountId), ['external_id' => $externalId])
            ->throw();
    }

    /**
     * Revoke the provider's grant. A 404 means it is already gone —
     * that is the outcome we wanted.
     *
     * @throws ConnectionException
     */
    public function disconnect(string $accountId): void
    {
        $this->tolerantSend(fn () => $this->client()->post("/social-accounts/{$accountId}/disconnect"));
    }

    /**
     * Remove the account from the workspace entirely.
     *
     * @throws ConnectionException
     */
    public function deleteAccount(string $accountId): void
    {
        $this->tolerantSend(fn () => $this->client()->delete("/social-accounts/{$accountId}"));
    }

    /**
     * Create (and schedule) a post.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws ConnectionException
     */
    public function createPost(array $payload): array
    {
        $response = $this->client()->post('/social-posts', $payload);

        $response->throw();

        $data = (array) $response->json();

        if (! is_string($data['id'] ?? null) || $data['id'] === '') {
            throw new RuntimeException('Post for Me returned no post id.');
        }

        return $data;
    }

    /**
     * Replace a post that has not gone out yet. The provider's PUT takes the
     * full create body — anything left out is dropped, not kept.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws ConnectionException
     */
    public function updatePost(string $postId, array $payload): void
    {
        $this->client()->put("/social-posts/{$postId}", $payload)->throw();
    }

    /**
     * Per-destination results for these posts — what the result webhook
     * would have said, asked for when it did not arrive.
     *
     * @param  list<string>  $postIds
     * @return array<int, array<string, mixed>>
     *
     * @throws ConnectionException
     */
    public function resultsFor(array $postIds): array
    {
        // Repeated `post_id=` params, the way the provider reads an OR list.
        $query = implode('&', array_map(fn ($id) => 'post_id='.urlencode($id), $postIds));
        $response = $this->client()->get('/social-post-results?limit=100&'.$query);

        $response->throw();

        return (array) $response->json('data', []);
    }

    /**
     * Cancel a post that has not gone out yet.
     *
     * @throws ConnectionException
     */
    public function deletePost(string $postId): void
    {
        $this->tolerantSend(fn () => $this->client()->delete("/social-posts/{$postId}"));
    }

    /**
     * Every post on the account — ours and ones made outside — with its
     * metrics. Needs the account connected with the "feeds" permission.
     *
     * @return array{data: array<int, array<string, mixed>>, cursor: string|null}
     *
     * @throws ConnectionException
     */
    public function feed(string $accountId, ?string $cursor = null, int $limit = 25): array
    {
        $response = $this->client()->get("/social-account-feeds/{$accountId}", array_filter([
            'expand' => 'metrics',
            'limit' => $limit,
            'cursor' => $cursor,
        ]));

        $response->throw();

        return [
            'data' => (array) $response->json('data', []),
            'cursor' => $response->json('meta.next_cursor') ?? $response->json('meta.cursor'),
        ];
    }

    /**
     * The webhook's only authentication: the shared secret issued when the
     * webhook was registered, echoed back in a header on every delivery.
     * An unconfigured secret rejects everything — never open by default.
     */
    public function verifyWebhook(Request $request): bool
    {
        $secret = (string) config('services.postforme.webhook_secret');

        if ($secret === '') {
            return false;
        }

        return hash_equals($secret, (string) $request->header('Post-For-Me-Webhook-Secret'));
    }

    /**
     * The PUT takes the full body, destinations included, so a changed
     * destination list is an ordinary replace and the id stays.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws ConnectionException
     */
    public function replaceTargets(string $postId, array $payload): string
    {
        $this->updatePost($postId, $payload);

        return $postId;
    }

    /**
     * Post for Me's events are already in our shape; only the names map.
     * The wrapper's field name is not pinned down by the docs — both
     * spellings are accepted rather than dropping deliveries.
     *
     * @return list<array{type: string, data: array<string, mixed>}>
     */
    public function webhookEvents(Request $request): array
    {
        $type = match ((string) ($request->input('event_type') ?? $request->input('type'))) {
            'social.account.created', 'social.account.updated' => 'account',
            'social.post.updated' => 'post',
            'social.post.result.created' => 'result',
            default => null,
        };

        return $type === null ? [] : [['type' => $type, 'data' => (array) $request->input('data', [])]];
    }

    private function client(): PendingRequest
    {
        $key = (string) config('services.postforme.key');

        if ($key === '') {
            throw new RuntimeException('Post for Me API key is not configured.');
        }

        return Http::withToken($key)
            ->acceptJson()
            ->baseUrl((string) config('services.postforme.base_url'));
    }

    /**
     * Send, swallowing only 404 — "already deleted" is success for the
     * delete-shaped calls; anything else still throws.
     *
     * @param  callable(): Response  $send
     *
     * @throws ConnectionException
     */
    private function tolerantSend(callable $send): void
    {
        try {
            $send()->throw();
        } catch (RequestException $e) {
            if ($e->response->status() !== 404) {
                throw $e;
            }
        }
    }

    /*
     * No Inbox on Post for Me: every call says so plainly. Accounts held
     * here reconnect through Outstand to get DMs and comments.
     */

    public function supportsInbox(): bool
    {
        return false;
    }

    public function conversations(string $accountId, ?string $cursor = null, int $limit = 50): array
    {
        throw InboxUnsupported::for('Post for Me', 'direct messages');
    }

    public function conversation(string $conversationId): ?array
    {
        throw InboxUnsupported::for('Post for Me', 'direct messages');
    }

    public function messages(string $conversationId, ?string $cursor = null, int $limit = 50): array
    {
        throw InboxUnsupported::for('Post for Me', 'direct messages');
    }

    public function sendMessage(string $conversationId, array $message): array
    {
        throw InboxUnsupported::for('Post for Me', 'direct messages');
    }

    public function deleteScheduledMessage(string $conversationId, string $messageId): void
    {
        throw InboxUnsupported::for('Post for Me', 'direct messages');
    }

    public function markConversationRead(string $conversationId): void
    {
        throw InboxUnsupported::for('Post for Me', 'direct messages');
    }

    public function privateReply(string $accountId, string $commentId, ?string $authorId, string $content): array
    {
        throw InboxUnsupported::for('Post for Me', 'private replies');
    }

    public function comments(string $postId, string $network, string $accountUsername): array
    {
        throw InboxUnsupported::for('Post for Me', 'reading comments');
    }

    public function replyToComment(string $postId, string $commentId, string $content, string $accountUsername): array
    {
        throw InboxUnsupported::for('Post for Me', 'replying to comments');
    }

    /** Legacy relay: boards are not listed through it. */
    public function pinterestBoards(string $accountId): array
    {
        return [];
    }

    /** Not read from this relay: accounts on it are legacy. */
    public function followersCount(string $accountId): ?int
    {
        return null;
    }
}
