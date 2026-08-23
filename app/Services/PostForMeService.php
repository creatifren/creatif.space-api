<?php

namespace App\Services;

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
class PostForMeService
{
    /**
     * Auth URL the user is redirected to in order to sign in on the
     * platform and grant the account. `external_id` tags the resulting
     * account with our user, which is what sync and webhooks key on.
     *
     * @throws ConnectionException
     */
    public function authUrl(string $platform, string $externalId): string
    {
        // Where the browser lands after granting is the PROJECT's redirect
        // URL, set once in the Post for Me dashboard — point it at
        // {frontend}/social/connect?social=connected. Quickstart projects
        // reject a per-request redirect_url_override outright (400), so it
        // is deliberately not sent here.
        $payload = [
            'platform' => $platform,
            'external_id' => $externalId,
        ];

        // Two platforms demand a connection_type, and on the provider's own
        // system credentials only one value works: Instagram must go through
        // Login with Facebook, LinkedIn through "organization".
        if ($platform === 'instagram') {
            $payload['platform_data'] = ['instagram' => ['connection_type' => 'facebook']];
        } elseif ($platform === 'linkedin') {
            $payload['platform_data'] = ['linkedin' => ['connection_type' => 'organization']];
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
     * Cancel a post that has not gone out yet.
     *
     * @throws ConnectionException
     */
    public function deletePost(string $postId): void
    {
        $this->tolerantSend(fn () => $this->client()->delete("/social-posts/{$postId}"));
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
}
