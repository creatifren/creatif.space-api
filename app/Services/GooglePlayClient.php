<?php

namespace App\Services;

use App\Exceptions\GooglePlayRefused;
use App\Exceptions\GooglePlayUnavailable;
use Firebase\JWT\JWT;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The Google Play Developer API, as the service account in
 * services.google_play.service_account_json_path.
 *
 * Auth is the OAuth2 service-account flow: a JWT signed with the account's
 * private key is exchanged for an access token (androidpublisher scope),
 * cached until shortly before it expires. The key file is read on demand
 * and never logged; purchase tokens never reach a log or an exception
 * message either.
 */
class GooglePlayClient
{
    public const SCOPE = 'https://www.googleapis.com/auth/androidpublisher';

    public const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    public const API_URL = 'https://androidpublisher.googleapis.com/androidpublisher/v3/applications/';

    private const CACHE_KEY = 'google-play:access-token:';

    /**
     * purchases.subscriptionsv2.get — the subscription as Google has it now.
     *
     * @return array<string, mixed>
     *
     * @throws GooglePlayRefused invalid_token (Google answered 400/404/410)
     * @throws GooglePlayUnavailable
     */
    public function getSubscriptionV2(string $purchaseToken): array
    {
        $url = $this->app().'/purchases/subscriptionsv2/tokens/'.rawurlencode($purchaseToken);
        $response = $this->send(fn (PendingRequest $http) => $http->get($url));

        if (in_array($response->status(), [400, 404, 410], true)) {
            throw GooglePlayRefused::invalidToken();
        }
        $body = $response->json();
        if (! $response->successful() || ! is_array($body)) {
            throw new GooglePlayUnavailable('subscriptionsv2.get answered HTTP '.$response->status());
        }

        return $body;
    }

    /**
     * purchases.subscriptions.acknowledge. Unacknowledged purchases are
     * refunded by Google after three days, so callers retry on the next
     * verify or notification while Google still says PENDING.
     *
     * @throws GooglePlayUnavailable
     */
    public function acknowledge(string $productId, string $purchaseToken): void
    {
        $url = $this->app().'/purchases/subscriptions/'.rawurlencode($productId)
            .'/tokens/'.rawurlencode($purchaseToken).':acknowledge';
        $response = $this->send(fn (PendingRequest $http) => $http->withBody('{}', 'application/json')->post($url));

        if (! $response->successful()) {
            throw new GooglePlayUnavailable('acknowledge answered HTTP '.$response->status());
        }
    }

    private function app(): string
    {
        return self::API_URL.rawurlencode((string) config('services.google_play.package_name'));
    }

    /**
     * One call with a cached token; a 401 drops the token and tries once more.
     *
     * @param  callable(PendingRequest): Response  $call
     */
    private function send(callable $call): Response
    {
        $account = $this->serviceAccount();

        foreach ([false, true] as $retry) {
            try {
                $response = $call(Http::timeout(10)->acceptJson()->withToken($this->accessToken($account)));
            } catch (ConnectionException $e) {
                throw new GooglePlayUnavailable('Google Play API unreachable: '.class_basename($e), 0, $e);
            }
            if ($response->status() !== 401 || $retry) {
                return $response;
            }
            Cache::forget(self::CACHE_KEY.sha1($account['client_email']));
        }

        throw new GooglePlayUnavailable('unreachable');
    }

    /**
     * @param  array{client_email: string, private_key: string, token_uri: string}  $account
     */
    private function accessToken(array $account): string
    {
        $key = self::CACHE_KEY.sha1($account['client_email']);
        $cached = Cache::get($key);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $now = now()->getTimestamp();
        try {
            $assertion = JWT::encode([
                'iss' => $account['client_email'],
                'scope' => self::SCOPE,
                'aud' => $account['token_uri'],
                'iat' => $now,
                'exp' => $now + 3600,
            ], $account['private_key'], 'RS256');
        } catch (Throwable $e) {
            throw new GooglePlayUnavailable('service account key unusable: '.class_basename($e));
        }

        try {
            $response = Http::timeout(10)->acceptJson()->asForm()->post($account['token_uri'], [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $assertion,
            ]);
        } catch (ConnectionException $e) {
            throw new GooglePlayUnavailable('Google OAuth unreachable: '.class_basename($e), 0, $e);
        }

        $token = $response->json('access_token');
        if (! $response->successful() || ! is_string($token) || $token === '') {
            throw new GooglePlayUnavailable('Google OAuth answered HTTP '.$response->status());
        }

        $ttl = (int) ($response->json('expires_in') ?? 3600) - 60;
        if ($ttl > 0) {
            Cache::put($key, $token, $ttl);
        }

        return $token;
    }

    /**
     * @return array{client_email: string, private_key: string, token_uri: string}
     */
    private function serviceAccount(): array
    {
        $path = (string) config('services.google_play.service_account_json_path');
        if ($path === '' || ! is_file($path) || ! is_readable($path)) {
            throw new GooglePlayUnavailable('service account file missing or unreadable');
        }

        $json = json_decode((string) file_get_contents($path), true);
        $email = is_array($json) ? ($json['client_email'] ?? null) : null;
        $privateKey = is_array($json) ? ($json['private_key'] ?? null) : null;
        if (! is_string($email) || $email === '' || ! is_string($privateKey) || $privateKey === '') {
            throw new GooglePlayUnavailable('service account file is not a service account key');
        }

        // Pinned rather than read from the file: the signed assertion only
        // ever goes to Google's token endpoint.
        return ['client_email' => $email, 'private_key' => $privateKey, 'token_uri' => self::TOKEN_URL];
    }
}
