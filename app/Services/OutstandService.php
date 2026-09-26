<?php

namespace App\Services;

use App\Enums\SocialPlatform;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Outstand, by hand — the relay that delivers posts to the platforms. One
 * API key means one org shared by every user of ours, and Outstand has no
 * notion of our users at all: who owns which account is decided in the
 * connect callback and enforced on our side before any call is made.
 */
class OutstandService
{
    /**
     * Where the browser is sent to grant an account. The nonce rides in the
     * return URL's path, so the callback learns which user and platform it
     * belongs to without trusting anything Outstand appends.
     */
    public function connectUrl(User $user, SocialPlatform $platform): string
    {
        $orgId = (string) config('services.outstand.org_id');

        if ($orgId === '') {
            throw new RuntimeException('Outstand org id is not configured.');
        }

        $nonce = Str::random(40);

        Cache::put(self::nonceKey($nonce), [
            'user_id' => $user->id,
            'platform' => $platform->value,
        ], now()->addMinutes(15));

        return config('services.outstand.connect_url')."/{$platform->value}/{$orgId}?"
            .http_build_query(['redirect_uri' => route('api.v1.social-accounts.callback', $nonce)]);
    }

    /**
     * Single use: a replayed return URL finds nothing.
     *
     * @return array{user_id: int, platform: string}|null
     */
    public function pullNonce(string $nonce): ?array
    {
        return Cache::pull(self::nonceKey($nonce));
    }

    /**
     * Every account in the org — everyone's, not one user's.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws ConnectionException
     */
    public function accounts(): array
    {
        $response = $this->client()->get('/social-accounts');

        $response->throw();

        return (array) $response->json('data', []);
    }

    /**
     * Create (and schedule) one post to the given accounts. Outstand drops
     * account ids it cannot resolve without an error, so a short answer is
     * undone and refused rather than recorded as sent.
     *
     * @param  array<int, string>  $accountIds
     * @param  array<int, array{url: string}>  $media
     * @return array<string, mixed>
     *
     * @throws ConnectionException
     */
    public function createPost(string $caption, array $accountIds, array $media, ?Carbon $scheduledAt): array
    {
        $payload = [
            'containers' => [[
                'content' => $caption,
                'media' => array_map(fn (array $m) => [
                    'url' => $m['url'],
                    'filename' => basename((string) parse_url($m['url'], PHP_URL_PATH)),
                ], $media),
            ]],
            'accounts' => $accountIds,
        ];

        if ($scheduledAt !== null) {
            $payload['scheduledAt'] = $scheduledAt->toIso8601String();
        }

        $response = $this->client()->post('/posts', $payload);

        $response->throw();

        $data = (array) ($response->json('data') ?? $response->json());

        if (! is_string($data['id'] ?? null) || $data['id'] === '') {
            throw new RuntimeException('Outstand returned no post id.');
        }

        if (count((array) ($data['socialAccounts'] ?? [])) < count($accountIds)) {
            $this->deletePost($data['id']);

            throw new RuntimeException('Outstand did not accept every account.');
        }

        return $data;
    }

    /**
     * Cancel a post that has not gone out yet. A 404 means it is already
     * gone — the outcome we wanted.
     *
     * @throws ConnectionException
     */
    public function deletePost(string $postId): void
    {
        try {
            $this->client()->delete("/posts/{$postId}")->throw();
        } catch (RequestException $e) {
            if ($e->response->status() !== 404) {
                throw $e;
            }
        }
    }

    /**
     * HMAC-SHA256 of the raw body, keyed with the secret typed into their
     * dashboard. An unconfigured secret rejects everything — never open by
     * default.
     */
    public function verifyWebhook(Request $request): bool
    {
        $secret = (string) config('services.outstand.webhook_secret');

        if ($secret === '') {
            return false;
        }

        return hash_equals(
            'sha256='.hash_hmac('sha256', $request->getContent(), $secret),
            (string) $request->header('X-Outstand-Signature'),
        );
    }

    private function client(): PendingRequest
    {
        $key = (string) config('services.outstand.key');

        if ($key === '') {
            throw new RuntimeException('Outstand API key is not configured.');
        }

        return Http::withToken($key)
            ->acceptJson()
            ->baseUrl((string) config('services.outstand.base_url'));
    }

    private static function nonceKey(string $nonce): string
    {
        return "social-connect:{$nonce}";
    }
}
