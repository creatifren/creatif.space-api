<?php

namespace App\Services;

use App\Contracts\SocialProvider;
use App\Exceptions\ProviderConnectRefused;
use App\Exceptions\ProviderRateLimited;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Outstand (outstand.so), by hand. Same tenancy as Post for Me: one key,
 * one shared organization, our user's ulid as Outstand's `tenant_id`.
 * Everything is translated to and from the shapes in SocialProvider, so
 * the rest of the app never sees Outstand's.
 *
 * Docs: https://www.outstand.so/docs (llms-full.txt), read 2026-11-08.
 */
class OutstandService implements SocialProvider
{
    /** How long the OAuth `state` we round-trip stays good. */
    private const STATE_MINUTES = 60;

    /** Cache key prefix: short return-state key → our tenant (user ulid). */
    private const STATE_PREFIX = 'outstand-return:';

    /** The Instagram scope DMs need. */
    public const MESSAGES_SCOPE = 'instagram_business_manage_messages';

    /** Set while an Instagram sign-in that asked for the messages scope is in flight. */
    public const MESSAGES_SCOPE_MARKER = 'inbox-messages-scope:';

    /** Shared across workers: when Outstand said to stop calling, and until when. */
    private const RATE_LIMIT_KEY = 'outstand-rate-limited-until';

    public function name(): string
    {
        return 'outstand';
    }

    /**
     * OAuth networks get an auth URL whose redirect is our return route,
     * carrying the tenant sealed in `state` — Facebook, LinkedIn and Google
     * come back with a pending `session` that must be finalized under it.
     * Bluesky has no OAuth: it connects right here, and the "URL" is the
     * connect page the browser would have returned to.
     *
     * @param  array{handle?: string, app_password?: string}  $bluesky
     *
     * @throws ConnectionException
     */
    public function authUrl(string $platform, string $externalId, array $bluesky = []): string
    {
        if ($platform === 'bluesky') {
            self::refusedIf4xx($platform, $this->client()->post('/social-accounts/bluesky', [
                'handle' => $bluesky['handle'] ?? '',
                'appPassword' => $bluesky['app_password'] ?? '',
                'tenantId' => $externalId,
            ]))->throw();

            return config('app.frontend_url').'/social/connect?social=connected';
        }

        $redirect = route('api.v1.webhooks.outstand.return', ['state' => self::seal($externalId, $platform)]);
        // Outstand validates redirect_uri as an absolute URI: a relative or
        // non-http APP_URL would come back as a 400 for every connect.
        if (filter_var($redirect, FILTER_VALIDATE_URL) === false || ! preg_match('#^https?://#', $redirect)) {
            throw new RuntimeException("Outstand redirect_uri is not an absolute URL ({$redirect}); check APP_URL.");
        }

        $body = [
            'tenant_id' => $externalId,
            'redirect_uri' => $redirect,
            // Always the account picker: a second connect otherwise reuses
            // the account the browser is signed in to (honoured by TikTok,
            // Facebook, Instagram, YouTube; ignored elsewhere).
            'force_account_selection' => true,
        ];

        // DMs need instagram_business_manage_messages. `scopes` REPLACES
        // Outstand's defaults, so the whole default list goes with it. It is
        // a comma-separated STRING in Outstand's schema: an array is refused
        // with a 400 (prod, 9 Nov — no Instagram account could connect).
        if ($platform === 'instagram' && config('services.inbox.enabled')) {
            $body['scopes'] = implode(',', self::instagramScopes());
            Cache::put(self::MESSAGES_SCOPE_MARKER.$externalId, true, now()->addMinutes(self::STATE_MINUTES));
        }

        $response = $this->client()->post('/social-networks/'.rawurlencode($platform).'/auth-url', $body);

        self::refusedIf4xx($platform, $response)->throw();

        $url = $response->json('data.auth_url');

        if (! is_string($url) || $url === '') {
            throw new RuntimeException('Outstand returned no auth URL.');
        }

        self::startAttempt($externalId, $platform);

        return $url;
    }

    /**
     * @return array<int, array<string, mixed>>
     *
     * @throws ConnectionException
     */
    public function accountsFor(string $externalId): array
    {
        $out = [];

        // Paged by offset, oldest first; 100 is the cap. Five pages is far
        // past any plan's account quota.
        for ($page = 0; $page < 5; $page++) {
            $response = $this->client()->get('/social-accounts', [
                'tenantId' => $externalId,
                'limit' => 100,
                'offset' => $page * 100,
            ]);

            $response->throw();

            $rows = (array) $response->json('data', []);
            foreach ($rows as $row) {
                $out[] = self::normaliseAccount((array) $row);
            }

            if (count($rows) < 100) {
                break;
            }
        }

        return $out;
    }

    /**
     * There is no GET by id; the list filters by it exactly.
     *
     * @return array<string, mixed>|null
     *
     * @throws ConnectionException
     */
    public function account(string $accountId): ?array
    {
        $response = $this->client()->get('/social-accounts', ['id' => $accountId, 'limit' => 1]);

        if ($response->status() === 404) {
            return null;
        }

        $response->throw();

        $row = $response->json('data.0');

        return is_array($row) ? self::normaliseAccount($row) : null;
    }

    /**
     * No documented call moves an account to another tenant. A claim is a
     * fresh sign-in under the claimant's own tenant instead.
     */
    public function canReassign(): bool
    {
        return false;
    }

    public function assignAccount(string $accountId, string $externalId): void
    {
        throw new RuntimeException('Outstand cannot move an account to another tenant.');
    }

    /**
     * Outstand has one call for both — delete. Disconnect is that delete;
     * the delete that follows meets a 404, which is success.
     *
     * @throws ConnectionException
     */
    public function disconnect(string $accountId): void
    {
        $this->deleteAccount($accountId);
    }

    /** @throws ConnectionException */
    public function deleteAccount(string $accountId): void
    {
        $this->tolerantSend(fn () => $this->client()->delete('/social-accounts/'.rawurlencode($accountId)));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{id: string, status: string|null}
     *
     * @throws ConnectionException
     */
    public function createPost(array $payload): array
    {
        return $this->create($payload, (string) ($payload['external_id'] ?? ''));
    }

    /**
     * PATCH changes only what is sent; content, schedule and per-network
     * config are all sent, so it reads as a replace. Destinations cannot
     * change here — see replaceTargets.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws ConnectionException
     */
    public function updatePost(string $postId, array $payload): void
    {
        $body = $this->body($payload);
        unset($body['accounts']);
        // No slot means "now", as on create.
        $body['scheduledAt'] ??= null;

        $this->client()->patch('/posts/'.rawurlencode($postId), $body)->throw();
    }

    /**
     * Outstand cannot change a post's accounts, so a changed list is a new
     * post: created first, then the old one cancelled. If the cancel fails
     * the new one is taken back too, so nothing goes out twice.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws ConnectionException
     */
    public function replaceTargets(string $postId, array $payload): string
    {
        $current = $this->client()->get('/posts/'.rawurlencode($postId));
        $current->throw();

        $had = array_map(fn ($a) => (string) ($a['id'] ?? ''), (array) $current->json('post.socialAccounts', []));
        $wants = array_map('strval', (array) ($payload['social_accounts'] ?? []));
        sort($had);
        sort($wants);

        if ($had === $wants) {
            $this->updatePost($postId, $payload);

            return $postId;
        }

        $new = $this->create($payload, ($payload['external_id'] ?? $postId).'-'.substr(sha1(implode(',', $wants)), 0, 12));

        try {
            $this->deletePost($postId);
        } catch (Throwable $e) {
            $this->deletePost($new['id']);

            throw $e;
        }

        return $new['id'];
    }

    /**
     * GET /pinterest/accounts/{id}/boards → {data: [{id, name, …}]}.
     *
     * @return list<array{id: string, name: string}>
     *
     * @throws ConnectionException
     */
    public function pinterestBoards(string $accountId): array
    {
        $response = $this->client()->get('/pinterest/accounts/'.rawurlencode($accountId).'/boards');
        $response->throw();

        return array_values(array_filter(array_map(fn ($b) => [
            'id' => (string) ($b['id'] ?? ''),
            'name' => (string) ($b['name'] ?? ''),
        ], (array) $response->json('data', [])), fn ($b) => $b['id'] !== ''));
    }

    /** @throws ConnectionException */
    public function deletePost(string $postId): void
    {
        $this->tolerantSend(fn () => $this->client()->delete('/posts/'.rawurlencode($postId)));
    }

    /**
     * No batch read: one GET per post. Only finished destinations count —
     * `pending` is not a result yet, `deleted` is not ours to report.
     *
     * @param  list<string>  $postIds
     * @return array<int, array<string, mixed>>
     *
     * @throws ConnectionException
     */
    public function resultsFor(array $postIds): array
    {
        $out = [];

        foreach ($postIds as $postId) {
            $response = $this->client()->get('/posts/'.rawurlencode($postId));

            if ($response->status() === 404) {
                continue;
            }

            $response->throw();

            foreach ((array) $response->json('post.socialAccounts', []) as $a) {
                $status = $a['status'] ?? null;

                if ($status === 'published' || $status === 'failed') {
                    $out[] = self::result($postId, (string) ($a['id'] ?? ''), $status === 'published', $a['error'] ?? null, $a['platformPostUrl'] ?? null);
                }
            }
        }

        return $out;
    }

    /**
     * Outstand has no live account feed: this is the account's posts as
     * Outstand holds them — made through us, or brought in by an import
     * job — with each published one's analytics. One analytics call per
     * post, so the page is kept small; the caller caches it.
     *
     * @return array{data: array<int, array<string, mixed>>, cursor: string|null}
     *
     * @throws ConnectionException
     */
    public function feed(string $accountId, ?string $cursor = null, int $limit = 25): array
    {
        $offset = max(0, (int) $cursor);
        $response = $this->client()->get('/posts', [
            'social_account_id' => $accountId,
            'limit' => $limit,
            'offset' => $offset,
        ]);

        $response->throw();

        $posts = (array) ($response->json('data') ?? $response->json('posts') ?? []);
        $items = [];

        foreach ($posts as $post) {
            $mine = collect((array) ($post['socialAccounts'] ?? []))
                ->first(fn ($a) => (string) ($a['id'] ?? '') === $accountId);

            if (($mine['status'] ?? null) !== 'published') {
                continue;
            }

            $container = (array) ($post['containers'][0] ?? []);
            $items[] = [
                'platform_post_id' => (string) ($mine['platformPostId'] ?? ''),
                'caption' => (string) ($container['content'] ?? ''),
                'platform_url' => $mine['platformPostUrl'] ?? null,
                'posted_at' => $mine['publishedAt'] ?? $post['publishedAt'] ?? null,
                'media' => array_values(array_filter(array_map(
                    fn ($m) => is_array($m) ? ($m['url'] ?? null) : null,
                    (array) ($container['media'] ?? []),
                ))),
                'provider_post_id' => (string) ($post['id'] ?? ''),
                'metrics' => $this->metricsFor((string) ($post['id'] ?? ''), $accountId),
            ];
        }

        return [
            'data' => $items,
            'cursor' => count($posts) >= $limit ? (string) ($offset + $limit) : null,
        ];
    }

    /**
     * `X-Outstand-Signature: sha256=<hex HMAC-SHA256 of the raw body>`.
     * Outstand makes the secret optional; we do not — unset rejects all.
     */
    public function verifyWebhook(Request $request): bool
    {
        $secret = (string) config('services.outstand.webhook_secret');

        if ($secret === '') {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expected, (string) $request->header('X-Outstand-Signature'));
    }

    /**
     * post.published / post.error → one result per destination listed.
     * account.token_expired → the account lost its grant. There is no
     * "account connected" event: the return route's sync covers that.
     *
     * @return list<array{type: string, data: array<string, mixed>}>
     */
    public function webhookEvents(Request $request): array
    {
        $event = (string) $request->input('event');
        $data = (array) $request->input('data', []);

        if ($event === 'post.published' || $event === 'post.error') {
            $postId = (string) ($data['postId'] ?? '');

            return array_values(array_map(fn ($a) => [
                'type' => 'result',
                'data' => self::result(
                    $postId,
                    (string) ($a['accountId'] ?? ''),
                    $event === 'post.published' && empty($a['error']),
                    $a['error'] ?? null,
                    $a['platformPostUrl'] ?? null,
                ),
            ], (array) ($data['socialAccounts'] ?? [])));
        }

        if ($event === 'account.token_expired') {
            return [['type' => 'account.lost', 'data' => ['id' => (string) ($data['accountId'] ?? '')]]];
        }

        $inbox = self::inboxEvent($event, $data);

        if ($inbox === null) {
            return [];
        }

        // Outstand's own delivery id when it sends one; otherwise the event
        // and the id of the thing it is about — a redelivery has both.
        $natural = match ($event) {
            'message.read' => ($data['conversationId'] ?? '').'|'.($data['readAt'] ?? $data['messageId'] ?? $request->input('timestamp') ?? sha1($request->getContent())),
            'conversation.started' => (string) ($data['conversationId'] ?? ''),
            'comment.received' => (string) ($data['commentId'] ?? ''),
            default => (string) ($data['messageId'] ?? sha1($request->getContent())),
        };
        $delivery = $request->input('id') ?? $request->header('X-Outstand-Event-Id');
        $inbox['id'] = is_string($delivery) && $delivery !== ''
            ? hash('sha256', 'delivery|'.$delivery)
            : hash('sha256', $event.'|'.$natural);

        return [$inbox];
    }

    /**
     * Outstand's inbox webhook as one of ours, or null when it is not one.
     *
     * @param  array<string, mixed>  $d
     * @return array{type: string, data: array<string, mixed>}|null
     */
    private static function inboxEvent(string $event, array $d): ?array
    {
        $conversation = (string) ($d['conversationId'] ?? '');

        return match ($event) {
            'conversation.started' => ['type' => 'conversation', 'data' => self::normaliseConversation(['id' => $conversation] + $d)],
            'message.received' => ['type' => 'message', 'data' => [
                'id' => (string) ($d['messageId'] ?? ''),
                'conversation_id' => $conversation,
                'network' => $d['network'] ?? null,
                'direction' => 'in',
                'content' => $d['content'] ?? null,
                'media_urls' => self::mediaUrls($d['mediaUrls'] ?? $d['media_urls'] ?? $d['attachments'] ?? []),
                'status' => 'sent',
                'source' => 'contact',
                'contact_id' => $d['senderId'] ?? null,
                'sent_at' => $d['sentAt'] ?? null,
            ]],
            'message.sent' => ['type' => 'message', 'data' => array_filter([
                'id' => (string) ($d['messageId'] ?? ''),
                'conversation_id' => $conversation,
                'network' => $d['network'] ?? null,
                'direction' => 'out',
                'content' => $d['content'] ?? null,
                'status' => 'sent',
                'source' => $d['source'] ?? null,
                'platform_message_id' => $d['platformMessageId'] ?? null,
                'sent_at' => $d['sentAt'] ?? null,
            ], fn ($v) => $v !== null)],
            'message.failed' => ['type' => 'message', 'data' => array_filter([
                'id' => (string) ($d['messageId'] ?? ''),
                'conversation_id' => $conversation,
                'network' => $d['network'] ?? null,
                'direction' => 'out',
                'content' => $d['content'] ?? null,
                'status' => 'failed',
                'source' => $d['source'] ?? null,
                'error' => self::errorText($d['error'] ?? null) ?? 'Instagram refused the message.',
            ], fn ($v) => $v !== null)],
            'message.read' => ['type' => 'message.read', 'data' => [
                'conversation_id' => $conversation,
                'read_at' => $d['readAt'] ?? $d['sentAt'] ?? null,
            ]],
            'comment.received' => ['type' => 'comment', 'data' => [
                'id' => (string) ($d['commentId'] ?? ''),
                'account_id' => (string) ($d['accountId'] ?? ''),
                'post_id' => $d['postId'] ?? null,
                'platform_post_id' => $d['platformPostId'] ?? null,
                'parent_comment_id' => $d['parentCommentId'] ?? null,
                'author_id' => $d['authorId'] ?? null,
                'author_username' => $d['authorUsername'] ?? null,
                'text' => $d['text'] ?? null,
                'network' => (string) ($d['network'] ?? ''),
                'created_at' => $d['createdAt'] ?? null,
            ]],
            default => null,
        };
    }

    /**
     * A Facebook / LinkedIn / Google sign-in ends in a pending session:
     * the pages it may connect. Null when it expired or is not ours.
     *
     * @return array{network: string, pages: list<array{id: string, name: string, username: string|null, type: string|null, profile_photo_url: string|null}>}|null
     *
     * @throws ConnectionException
     */
    public function pendingConnection(string $session): ?array
    {
        $response = $this->client()->get('/social-accounts/pending/'.rawurlencode($session));

        if ($response->status() === 404) {
            return null;
        }

        $response->throw();

        return [
            'network' => (string) $response->json('data.network'),
            'pages' => array_values(array_map(fn ($p) => [
                'id' => (string) ($p['id'] ?? ''),
                'name' => (string) ($p['name'] ?? ''),
                'username' => $p['username'] ?? null,
                'type' => $p['type'] ?? null,
                'profile_photo_url' => $p['profilePictureUrl'] ?? null,
            ], (array) $response->json('data.availablePages', []))),
        ];
    }

    /**
     * Connect the chosen pages of a pending session.
     *
     * @param  list<string>  $pageIds
     *
     * @throws ConnectionException
     */
    public function finalizeConnection(string $session, array $pageIds): void
    {
        $this->client()
            ->post('/social-accounts/pending/'.rawurlencode($session).'/finalize', ['selectedPageIds' => $pageIds])
            ->throw();
    }

    /**
     * Outstand's own signed connect session lasts 30 minutes. Past it,
     * Outstand's callback can no longer read our redirect_uri and shows its
     * own page; its auth-url has no failure or cancel redirect to change
     * that. The attempt kept below lets the app say "expired" itself.
     */
    public const SESSION_MINUTES = 30;

    private const ATTEMPT_PREFIX = 'outstand-connect-attempt:';

    /** Our tenant (and the network being connected), sealed for the OAuth round trip. */
    public static function seal(string $externalId, ?string $network = null): string
    {
        // A short random key, not an encrypted blob: it rides inside
        // Outstand's redirect_uri, so it stays URL-safe and well under any
        // length limit. The tenant lives server-side for the state's life.
        $key = Str::random(40);
        Cache::put(self::STATE_PREFIX.$key, ['tenant' => $externalId, 'network' => $network], now()->addMinutes(self::STATE_MINUTES));

        return $key;
    }

    /** The tenant a returning browser carries, or null when forged or stale. */
    public static function unseal(?string $state): ?string
    {
        return self::sealed($state)['tenant'] ?? null;
    }

    /**
     * What a state was sealed with, or null when forged or stale.
     *
     * @return array{tenant: string, network: string|null}|null
     */
    public static function sealed(?string $state): ?array
    {
        if (! self::wellFormed($state)) {
            return null;
        }
        $value = Cache::get(self::STATE_PREFIX.$state);

        // A state sealed before the network rode along holds the bare tenant.
        if (is_string($value)) {
            return ['tenant' => $value, 'network' => null];
        }
        if (is_array($value) && is_string($value['tenant'] ?? null)) {
            return ['tenant' => $value['tenant'], 'network' => is_string($value['network'] ?? null) ? $value['network'] : null];
        }

        return null;
    }

    /** Shaped like a state we issue (says nothing about whether it is live). */
    public static function wellFormed(?string $state): bool
    {
        return is_string($state) && preg_match('/^[A-Za-z0-9]{40}$/', $state) === 1;
    }

    /** One state, one return: the answer is kept so a refresh lands in the same place. */
    public static function spend(string $state, string $redirect): void
    {
        Cache::forget(self::STATE_PREFIX.$state);
        Cache::put(self::STATE_PREFIX.'used:'.$state, $redirect, now()->addMinutes(self::STATE_MINUTES));
    }

    /** Where a state already spent sent the browser, if it was spent. */
    public static function spent(?string $state): ?string
    {
        if (! self::wellFormed($state)) {
            return null;
        }
        $redirect = Cache::get(self::STATE_PREFIX.'used:'.$state);

        return is_string($redirect) ? $redirect : null;
    }

    /** A connect is under way for this tenant: which network, and until when. */
    public static function startAttempt(string $externalId, string $network): void
    {
        Cache::put(self::ATTEMPT_PREFIX.$externalId, [
            'network' => $network,
            'status' => 'pending',
            'reason' => null,
            'started_at' => now()->toIso8601String(),
            'expires_at' => now()->addMinutes(self::SESSION_MINUTES)->toIso8601String(),
            'finished_at' => null,
        ], now()->addDay());
    }

    /** How the tenant's last connect ended. */
    public static function finishAttempt(string $externalId, string $status, ?string $reason = null, ?string $network = null): void
    {
        $attempt = Cache::get(self::ATTEMPT_PREFIX.$externalId);
        $attempt = is_array($attempt) ? $attempt : ['network' => null, 'started_at' => null, 'expires_at' => null];

        Cache::put(self::ATTEMPT_PREFIX.$externalId, [
            ...$attempt,
            'network' => $network ?? $attempt['network'],
            'status' => $status,
            'reason' => $reason,
            'finished_at' => now()->toIso8601String(),
        ], now()->addDay());
    }

    /**
     * The tenant's last connect. One still pending past Outstand's session
     * window reads as failed / expired: that browser is stranded on Outstand.
     *
     * @return array<string, mixed>|null
     */
    public static function attempt(string $externalId): ?array
    {
        $attempt = Cache::get(self::ATTEMPT_PREFIX.$externalId);
        if (! is_array($attempt)) {
            return null;
        }

        if (($attempt['status'] ?? null) === 'pending' && is_string($attempt['expires_at'] ?? null)
            && now()->greaterThan($attempt['expires_at'])) {
            $attempt['status'] = 'failed';
            $attempt['reason'] = 'expired';
        }

        return $attempt;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{id: string, status: string|null}
     *
     * @throws ConnectionException
     */
    private function create(array $payload, string $idempotencyKey): array
    {
        $request = $this->client();

        // Makes a retried create — ours or the client's — the same post.
        if ($idempotencyKey !== '') {
            $request = $request->withHeaders(['Idempotency-Key' => $idempotencyKey]);
        }

        $response = $request->post('/posts', $this->body($payload));

        $response->throw();

        $id = $response->json('post.id');

        if (! is_string($id) || $id === '') {
            throw new RuntimeException('Outstand returned no post id.');
        }

        return [
            'id' => $id,
            // Same word Post for Me uses for "already out".
            'status' => $response->json('post.publishedAt') !== null ? 'processed' : 'scheduled',
        ];
    }

    /**
     * Our post body as Outstand's. Per-account captions and placements
     * become per-network blocks — Outstand overrides text per network, so
     * two accounts on one network share the last override given.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function body(array $payload): array
    {
        $accounts = array_values(array_map('strval', (array) ($payload['social_accounts'] ?? [])));
        $platforms = SocialAccount::query()
            ->whereIn('provider_account_id', $accounts)
            ->get(['provider_account_id', 'platform'])
            ->mapWithKeys(fn (SocialAccount $a) => [$a->provider_account_id => $a->platform->value])
            ->all();

        $media = [];
        $cover = null;
        foreach ((array) ($payload['media'] ?? []) as $i => $m) {
            $url = (string) ($m['url'] ?? '');
            $media[] = ['url' => $url, 'filename' => self::filename($url, (int) $i)];
            $cover ??= $m['thumbnail_url'] ?? null;
        }

        $networks = [];
        $placements = [];
        foreach ((array) ($payload['account_configurations'] ?? []) as $entry) {
            $platform = $platforms[(string) ($entry['social_account_id'] ?? '')] ?? null;
            $config = (array) ($entry['configuration'] ?? []);

            if ($platform === null) {
                continue;
            }

            if (isset($config['caption'])) {
                $networks[$platform]['content'] = (string) $config['caption'];
            }

            // A Pin needs its board (Outstand: pinterest.board_id, required);
            // title and link are optional.
            if ($platform === 'pinterest') {
                foreach (['board_id' => 'board_id', 'title' => 'title', 'link' => 'link'] as $ours => $theirs) {
                    if (is_string($config[$ours] ?? null) && $config[$ours] !== '') {
                        $networks['pinterest'][$theirs] = $config[$ours];
                    }
                }
            }

            $placement = $config['placement'] ?? null;
            $placements[$platform] = $placement;

            if ($placement === 'stories' && in_array($platform, ['instagram', 'facebook'], true)) {
                $networks[$platform]['publishAsStory'] = true;
            } elseif ($placement === 'reels' && $platform === 'facebook') {
                $networks['facebook']['publishAsReel'] = true;
            }
        }

        // A cover goes where the network takes one: Instagram on Reels only
        // (a single video posts as a Reel there), YouTube always.
        if (is_string($cover) && $cover !== '') {
            if (($placements['instagram'] ?? null) === 'reels') {
                $networks['instagram']['reelCoverUrl'] = $cover;
            }
            if (in_array('youtube', $platforms, true)) {
                $networks['youtube']['thumbnailUrl'] = $cover;
            }
        }

        // Outstand's TikTok default drops the video in the creator's inbox
        // as a draft; Post for Me published it. Publish it.
        if (in_array('tiktok', $platforms, true)) {
            $networks['tiktok'] = ($networks['tiktok'] ?? []) + [
                'postMode' => 'DIRECT_POST',
                'privacyLevel' => 'PUBLIC_TO_EVERYONE',
            ];
        }

        $body = ['accounts' => $accounts, 'containers' => [['content' => (string) ($payload['caption'] ?? ''), 'media' => $media]]] + $networks;

        if (isset($payload['scheduled_at'])) {
            // Outstand's pattern takes UTC with a Z only.
            $body['scheduledAt'] = CarbonImmutable::parse((string) $payload['scheduled_at'])->utc()->format('Y-m-d\TH:i:s\Z');
        }

        return $body;
    }

    /**
     * @param  array<string, mixed>  $a
     * @return array<string, mixed>
     */
    private static function normaliseAccount(array $a): array
    {
        return [
            'id' => (string) ($a['id'] ?? ''),
            'platform' => (string) ($a['network'] ?? ''),
            'username' => $a['username'] ?? $a['nickname'] ?? null,
            'profile_photo_url' => $a['profile_picture_url'] ?? null,
            // isActive is 1/0, null when unknown — unknown stays connected.
            'status' => ($a['isActive'] ?? 1) === 0 ? 'disconnected' : 'connected',
            'external_id' => $a['tenant_id'] ?? null,
            'access_token' => $a['tokens']['access_token'] ?? null,
            // Only when Outstand says; null means "not reported", not "none".
            'scopes' => is_array($a['scopes'] ?? $a['permissions'] ?? null) ? array_values($a['scopes'] ?? $a['permissions']) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function result(string $postId, string $accountId, bool $success, mixed $error, mixed $url): array
    {
        return [
            'id' => $postId.':'.$accountId,
            'post_id' => $postId,
            'social_account_id' => $accountId,
            'success' => $success,
            'error' => $success ? null : (is_string($error) && $error !== '' ? $error : 'the platform refused it'),
            'platform_data' => ['url' => is_string($url) ? $url : null],
        ];
    }

    /**
     * One post's numbers on one account, in the names the feed reads. A
     * post without analytics yet reads as no numbers, not an error.
     *
     * @return array<string, mixed>
     */
    private function metricsFor(string $postId, string $accountId): array
    {
        try {
            $response = $this->client()->get('/posts/'.rawurlencode($postId).'/analytics');
        } catch (ConnectionException) {
            return [];
        }

        if (! $response->successful()) {
            return [];
        }

        $row = collect((array) $response->json('metrics_by_account', []))
            ->first(fn ($r) => (string) ($r['social_account']['id'] ?? '') === $accountId);

        return array_filter((array) ($row['metrics'] ?? []), fn ($v) => $v !== null);
    }

    /** Outstand wants a filename with an extension for each file. */
    private static function filename(string $url, int $i): string
    {
        $name = basename((string) parse_url($url, PHP_URL_PATH));

        return $name !== '' && str_contains($name, '.') ? $name : "media-{$i}.jpg";
    }

    /**
     * A 4xx on a connect is Outstand's verdict on our request or our setup:
     * logged with its body and turned into a clear 422 for the app. A 5xx
     * still throws as before.
     *
     * @throws ProviderConnectRefused
     */
    private static function refusedIf4xx(string $platform, Response $response): Response
    {
        if ($response->clientError() && $response->status() !== 429) {
            throw ProviderConnectRefused::from('outstand', $platform, $response);
        }

        return $response;
    }

    private function client(bool $retry = true): PendingRequest
    {
        $key = (string) config('services.outstand.key');

        if ($key === '') {
            throw new RuntimeException('Outstand API key is not configured.');
        }

        $request = Http::withToken($key)
            ->acceptJson()
            ->baseUrl((string) config('services.outstand.base_url'));

        if (! $retry) {
            return $request;
        }

        return $request
            // Limits are dynamic; a 429 is retryable (Retry-After when sent).
            // Creates are safe to retry: they carry an Idempotency-Key.
            ->retry(3, fn (int $attempt, Throwable $e) => $e instanceof RequestException
                ? min(10, max(1, (int) $e->response->header('Retry-After'))) * 1000
                : 500 * $attempt,
                fn (Throwable $e) => $e instanceof RequestException && $e->response->status() === 429,
                throw: false);
    }

    /**
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

    /* ───────────────────────────── Inbox ───────────────────────────── */

    public function supportsInbox(): bool
    {
        return true;
    }

    /**
     * Outstand's default Instagram scopes plus the messages one, from
     * config (OUTSTAND_INSTAGRAM_SCOPES) — the defaults are Outstand's to
     * change, so they are not hard-wired here.
     *
     * @return list<string>
     */
    public static function instagramScopes(): array
    {
        $scopes = (array) config('services.outstand.instagram_scopes', []);
        $scopes[] = self::MESSAGES_SCOPE;

        return array_values(array_unique(array_filter(array_map('trim', $scopes))));
    }

    /**
     * @return array{data: list<array<string, mixed>>, cursor: string|null}
     *
     * @throws ConnectionException
     */
    public function conversations(string $accountId, ?string $cursor = null, int $limit = 50): array
    {
        $offset = max(0, (int) $cursor);
        $response = $this->inbox(fn (PendingRequest $c) => $c->get('/conversations', [
            'social_account_id' => $accountId,
            'network' => 'instagram',
            'limit' => $limit,
            'offset' => $offset,
        ]));
        $rows = self::rows($response, 'conversations');

        return [
            'data' => array_map(fn ($r) => self::normaliseConversation((array) $r) + ['social_account_id' => $accountId], $rows),
            'cursor' => self::nextCursor($response, count($rows), $limit, $offset),
        ];
    }

    /** @throws ConnectionException */
    public function conversation(string $conversationId): ?array
    {
        $response = $this->inbox(fn (PendingRequest $c) => $c->get('/conversations/'.rawurlencode($conversationId)), [404]);

        if ($response->status() === 404) {
            return null;
        }

        $row = $response->json('data') ?? $response->json('conversation') ?? $response->json();

        return is_array($row) ? self::normaliseConversation($row) : null;
    }

    /** @throws ConnectionException */
    public function messages(string $conversationId, ?string $cursor = null, int $limit = 50): array
    {
        $offset = max(0, (int) $cursor);
        $response = $this->inbox(fn (PendingRequest $c) => $c->get('/conversations/'.rawurlencode($conversationId).'/messages', [
            'limit' => $limit,
            'offset' => $offset,
        ]));
        $rows = self::rows($response, 'messages');

        return [
            'data' => array_map(fn ($r) => self::normaliseMessage((array) $r, $conversationId), $rows),
            'cursor' => self::nextCursor($response, count($rows), $limit, $offset),
        ];
    }

    /**
     * 202: accepted and `pending` until message.sent / message.failed.
     *
     * @throws ConnectionException
     */
    public function sendMessage(string $conversationId, array $message): array
    {
        $body = array_filter([
            'content' => $message['content'] ?? null,
            'media_urls' => ($message['media_urls'] ?? []) ?: null,
            'scheduled_at' => isset($message['scheduled_at'])
                ? CarbonImmutable::parse((string) $message['scheduled_at'])->utc()->format('Y-m-d\TH:i:s\Z')
                : null,
        ], fn ($v) => $v !== null && $v !== '');

        $response = $this->inbox(fn (PendingRequest $c) => $c->post('/conversations/'.rawurlencode($conversationId).'/messages', $body));
        $row = (array) ($response->json('data') ?? $response->json('message') ?? $response->json() ?? []);

        return self::normaliseMessage($row + [
            'direction' => 'outbound',
            'status' => 'pending',
            'content' => $body['content'] ?? null,
            'media_urls' => $body['media_urls'] ?? [],
            'source' => 'api',
        ], $conversationId);
    }

    /** @throws ConnectionException */
    public function deleteScheduledMessage(string $conversationId, string $messageId): void
    {
        $this->inbox(fn (PendingRequest $c) => $c->delete(
            '/conversations/'.rawurlencode($conversationId).'/messages/'.rawurlencode($messageId),
        ), [404]);
    }

    /** @throws ConnectionException */
    public function markConversationRead(string $conversationId): void
    {
        $this->inbox(fn (PendingRequest $c) => $c->post('/conversations/'.rawurlencode($conversationId).'/read'));
    }

    /**
     * POST /conversations with a comment opens the one private reply.
     * The docs show comment_id both at the top level and nested under
     * `instagram` (with author_id); `services.outstand.private_reply_shape`
     * picks one, and `auto` tries nested first and falls back to top level
     * when Outstand rejects the body over the comment id.
     *
     * @throws ConnectionException
     */
    public function privateReply(string $accountId, string $commentId, ?string $authorId, string $content): array
    {
        $nested = [
            'social_account_id' => $accountId,
            'content' => $content,
            'instagram' => array_filter(['comment_id' => $commentId, 'author_id' => $authorId]),
        ];
        $top = [
            'social_account_id' => $accountId,
            'comment_id' => $commentId,
            'content' => $content,
        ];

        $shape = (string) config('services.outstand.private_reply_shape', 'auto');
        $send = fn (array $body) => $this->inbox(fn (PendingRequest $c) => $c->post('/conversations', $body), [400, 422]);

        if ($shape === 'top_level') {
            $response = $send($top);
        } else {
            $response = $send($nested);

            if ($shape === 'auto'
                && in_array($response->status(), [400, 422], true)
                && str_contains(strtolower($response->body()), 'comment_id')) {
                $response = $send($top);
            }
        }

        $response->throw();

        $row = (array) ($response->json('data') ?? $response->json('conversation') ?? $response->json() ?? []);

        return [
            'conversation_id' => self::pick($row, 'conversationId', 'conversation_id', 'id'),
            'message_id' => self::pick($row, 'messageId', 'message_id', 'message.id'),
            'conversation' => isset($row['id']) || isset($row['conversationId']) ? self::normaliseConversation($row) : null,
        ];
    }

    /**
     * Comments, with their replies flattened in (each keeps its parent).
     *
     * @return list<array<string, mixed>>
     *
     * @throws ConnectionException
     */
    public function comments(string $postId, string $network, string $accountUsername): array
    {
        $response = $this->inbox(fn (PendingRequest $c) => $c->get('/posts/'.rawurlencode($postId).'/replies', [
            'network' => $network,
            'username' => ltrim($accountUsername, '@'),
            'include_replies' => 'true',
        ]));

        $out = [];
        $walk = function (array $rows, ?string $parent) use (&$walk, &$out, $postId) {
            foreach ($rows as $row) {
                $row = (array) $row;
                $comment = self::normaliseComment($row, $postId, $parent);
                if ($comment['id'] === '') {
                    continue;
                }
                $out[] = $comment;
                $walk((array) ($row['replies'] ?? []), $comment['id']);
            }
        };
        $walk(self::rows($response, 'replies'), null);

        return $out;
    }

    /** @throws ConnectionException */
    public function replyToComment(string $postId, string $commentId, string $content, string $accountUsername): array
    {
        $response = $this->inbox(fn (PendingRequest $c) => $c->post('/posts/'.rawurlencode($postId).'/replies', [
            'content' => $content,
            'account_username' => ltrim($accountUsername, '@'),
            'parent_comment_id' => $commentId,
        ]));
        $row = (array) ($response->json('data') ?? $response->json('reply') ?? $response->json() ?? []);

        return ['id' => self::pick($row, 'id', 'commentId', 'platformCommentId', 'platform_comment_id')];
    }

    /**
     * One Inbox call, rate-limit aware: refused up front while Outstand
     * has told us to wait, and the X-RateLimit-* / Retry-After headers of
     * every answer are kept for the next caller (the sync job releases
     * itself for that long). No blocking retries here — the job retries.
     * Any non-2XX outside $tolerated throws.
     *
     * @param  callable(PendingRequest): Response  $send
     * @param  list<int>  $tolerated
     *
     * @throws ConnectionException
     * @throws RequestException
     * @throws ProviderRateLimited
     */
    private function inbox(callable $send, array $tolerated = []): Response
    {
        $until = (int) Cache::get(self::RATE_LIMIT_KEY, 0);
        if ($until > now()->timestamp) {
            throw new ProviderRateLimited($until - now()->timestamp);
        }

        $response = $send($this->client(retry: false));

        $wait = null;
        if ($response->status() === 429) {
            $wait = self::seconds($response->header('Retry-After')) ?? self::seconds($response->header('X-RateLimit-Reset')) ?? 60;
        } elseif ($response->header('X-RateLimit-Remaining') !== '' && (int) $response->header('X-RateLimit-Remaining') <= 0) {
            $wait = self::seconds($response->header('X-RateLimit-Reset')) ?? 60;
        }

        if ($wait !== null) {
            Cache::put(self::RATE_LIMIT_KEY, now()->timestamp + $wait, now()->addSeconds($wait));
        }

        if ($response->status() === 429) {
            throw new ProviderRateLimited($wait ?? 60);
        }

        if (! $response->successful() && ! in_array($response->status(), $tolerated, true)) {
            $response->throw();
        }

        return $response;
    }

    /** Retry-After / X-RateLimit-Reset as seconds from now: delta or epoch. */
    private static function seconds(string $value): ?int
    {
        if ($value === '' || ! is_numeric($value)) {
            return null;
        }

        $n = (int) $value;

        // An epoch (seconds or ms) rather than a delta.
        if ($n > 1_000_000_000_000) {
            $n = intdiv($n, 1000);
        }
        if ($n > 1_000_000_000) {
            $n -= now()->timestamp;
        }

        return max(1, min($n, 3600));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function rows(Response $response, string $key): array
    {
        $rows = $response->json('data') ?? $response->json($key) ?? [];

        // `data` may itself wrap the list.
        if (is_array($rows) && isset($rows[$key]) && is_array($rows[$key])) {
            $rows = $rows[$key];
        }

        return is_array($rows) && array_is_list($rows) ? $rows : [];
    }

    private static function nextCursor(Response $response, int $count, int $limit, int $offset): ?string
    {
        $next = $response->json('meta.next_cursor') ?? $response->json('next_cursor') ?? $response->json('pagination.next_offset');
        if (is_scalar($next) && (string) $next !== '') {
            return (string) $next;
        }

        $more = $response->json('has_more') ?? $response->json('meta.has_more') ?? $response->json('pagination.has_more');

        return ($more ?? $count >= $limit) && $count > 0 ? (string) ($offset + $count) : null;
    }

    /**
     * @param  array<string, mixed>  $a
     */
    private static function pick(array $a, string ...$keys): mixed
    {
        foreach ($keys as $key) {
            $v = data_get($a, $key);
            if ($v !== null && $v !== '') {
                return $v;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $c
     * @return array<string, mixed>
     */
    private static function normaliseConversation(array $c): array
    {
        $contact = (array) (self::pick($c, 'contact', 'participant', 'recipient', 'participants.0') ?? []);
        $last = self::pick($c, 'lastMessage', 'last_message');

        return array_filter([
            'id' => (string) (self::pick($c, 'id', 'conversationId', 'conversation_id') ?? ''),
            'social_account_id' => self::pick($c, 'socialAccountId', 'social_account_id', 'accountId', 'social_account.id', 'socialAccount.id'),
            'network' => (string) (self::pick($c, 'network') ?? 'instagram'),
            'contact' => [
                'id' => self::pick($contact, 'id', 'platformId', 'platform_id') ?? self::pick($c, 'contactId', 'contact_id', 'senderId'),
                'username' => self::pick($contact, 'username', 'handle') ?? self::pick($c, 'contactUsername', 'contact_username'),
                'name' => self::pick($contact, 'name', 'displayName', 'display_name') ?? self::pick($c, 'contactName', 'contact_name'),
                'avatar_url' => self::pick($contact, 'avatarUrl', 'avatar_url', 'profilePictureUrl', 'profile_picture_url') ?? self::pick($c, 'contactAvatarUrl'),
            ],
            'last_message' => is_array($last) ? self::pick($last, 'content', 'text') : $last,
            'last_message_at' => self::pick($c, 'lastMessageAt', 'last_message_at', 'lastMessage.sentAt', 'last_message.sent_at', 'updatedAt', 'updated_at'),
            'last_inbound_at' => self::pick($c, 'lastInboundAt', 'last_inbound_at', 'lastInboundMessageAt', 'last_inbound_message_at'),
            'unread_count' => self::pick($c, 'unreadCount', 'unread_count'),
            'status' => self::pick($c, 'status'),
        ], fn ($v) => $v !== null);
    }

    /**
     * @param  array<string, mixed>  $m
     * @return array<string, mixed>
     */
    private static function normaliseMessage(array $m, string $conversationId): array
    {
        $direction = strtolower((string) (self::pick($m, 'direction') ?? 'inbound'));
        $status = strtolower((string) (self::pick($m, 'status') ?? 'sent'));

        return [
            'id' => (string) (self::pick($m, 'id', 'messageId', 'message_id') ?? ''),
            'conversation_id' => (string) (self::pick($m, 'conversationId', 'conversation_id') ?? $conversationId),
            'direction' => in_array($direction, ['out', 'outbound', 'outgoing', 'sent'], true) ? 'out' : 'in',
            'content' => self::pick($m, 'content', 'text'),
            'media_urls' => self::mediaUrls(self::pick($m, 'mediaUrls', 'media_urls', 'media', 'attachments') ?? []),
            'status' => match ($status) {
                'pending', 'queued', 'scheduled', 'sending' => 'pending',
                'failed', 'error' => 'failed',
                default => 'sent',
            },
            'error' => self::errorText(self::pick($m, 'error')),
            'source' => self::pick($m, 'source'),
            'platform_message_id' => self::pick($m, 'platformMessageId', 'platform_message_id'),
            'scheduled_at' => self::pick($m, 'scheduledAt', 'scheduled_at'),
            'sent_at' => self::pick($m, 'sentAt', 'sent_at', 'createdAt', 'created_at'),
        ];
    }

    /**
     * @param  array<string, mixed>  $c
     * @return array<string, mixed>
     */
    private static function normaliseComment(array $c, string $postId, ?string $parent): array
    {
        $author = self::pick($c, 'author');

        return [
            'id' => (string) (self::pick($c, 'id', 'commentId', 'comment_id', 'platformCommentId') ?? ''),
            'post_id' => $postId,
            'platform_post_id' => self::pick($c, 'platformPostId', 'platform_post_id'),
            'parent_comment_id' => self::pick($c, 'parentCommentId', 'parent_comment_id') ?? $parent,
            'author_id' => is_array($author) ? self::pick($author, 'id') : self::pick($c, 'authorId', 'author_id', 'from.id'),
            'author_username' => is_array($author)
                ? self::pick($author, 'username', 'name')
                : self::pick($c, 'authorUsername', 'author_username', 'username', 'from.username', 'author_name') ?? (is_string($author) ? $author : null),
            'text' => self::pick($c, 'text', 'content', 'message'),
            'created_at' => self::pick($c, 'createdAt', 'created_at', 'timestamp', 'created_time'),
        ];
    }

    /**
     * @return list<string>
     */
    private static function mediaUrls(mixed $media): array
    {
        return array_values(array_filter(array_map(
            fn ($m) => is_string($m) ? $m : (is_array($m) ? ($m['url'] ?? $m['payload']['url'] ?? null) : null),
            is_array($media) ? $media : [],
        ), 'is_string'));
    }

    private static function errorText(mixed $error): ?string
    {
        if (is_array($error)) {
            $error = $error['message'] ?? $error['code'] ?? null;
        }

        return is_string($error) && $error !== '' ? $error : null;
    }
}
