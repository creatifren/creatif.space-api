<?php

namespace App\Services;

use App\Contracts\SocialProvider;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use JsonException;
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
            $this->client()->post('/social-accounts/bluesky', [
                'handle' => $bluesky['handle'] ?? '',
                'appPassword' => $bluesky['app_password'] ?? '',
                'tenantId' => $externalId,
            ])->throw();

            return config('app.frontend_url').'/social/connect?social=connected';
        }

        $response = $this->client()->post('/social-networks/'.rawurlencode($platform).'/auth-url', [
            'tenant_id' => $externalId,
            'redirect_uri' => route('api.v1.webhooks.outstand.return', ['state' => self::seal($externalId)]),
            // Always the account picker: a second connect otherwise reuses
            // the account the browser is signed in to (honoured by TikTok,
            // Facebook, Instagram, YouTube; ignored elsewhere).
            'force_account_selection' => true,
        ]);

        $response->throw();

        $url = $response->json('data.auth_url');

        if (! is_string($url) || $url === '') {
            throw new RuntimeException('Outstand returned no auth URL.');
        }

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

        return [];
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

    /** Our tenant, sealed for the OAuth round trip. */
    public static function seal(string $externalId): string
    {
        return Crypt::encryptString(json_encode([
            't' => $externalId,
            'e' => now()->addMinutes(self::STATE_MINUTES)->timestamp,
        ], JSON_THROW_ON_ERROR));
    }

    /** The tenant a returning browser carries, or null when forged or stale. */
    public static function unseal(?string $state): ?string
    {
        try {
            $data = json_decode(Crypt::decryptString((string) $state), true, 512, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            return null;
        }

        return is_array($data) && is_string($data['t'] ?? null) && (int) ($data['e'] ?? 0) >= now()->timestamp
            ? $data['t']
            : null;
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

    private function client(): PendingRequest
    {
        $key = (string) config('services.outstand.key');

        if ($key === '') {
            throw new RuntimeException('Outstand API key is not configured.');
        }

        return Http::withToken($key)
            ->acceptJson()
            ->baseUrl((string) config('services.outstand.base_url'))
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
}
