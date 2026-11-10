<?php

namespace App\Exceptions;

use Illuminate\Http\Client\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * The relay refused to start a connect (a 4xx on auth-url or the Bluesky
 * sign-in). Its body is logged in full; the app gets a clear 422 with a
 * code instead of a bare 500.
 *
 * - provider_misconfigured: our side of the relay is set up wrong (e.g.
 *   Outstand "Redirect URL Override is not allowed for Quickstart
 *   Projects", a missing network, a bad key). No user can fix it; staff must.
 * - provider_connect_refused: anything else the relay refused for this
 *   request (e.g. a wrong Bluesky app password).
 */
class ProviderConnectRefused extends RuntimeException
{
    public function __construct(
        public readonly string $provider,
        public readonly string $platform,
        public readonly int $status,
        public readonly string $providerError,
        public readonly bool $misconfigured,
    ) {
        parent::__construct("{$provider} refused to connect {$platform} ({$status}): {$providerError}");
    }

    public static function from(string $provider, string $platform, Response $response): self
    {
        $body = mb_substr((string) $response->body(), 0, 2000);
        $error = (string) ($response->json('error') ?? $response->json('message') ?? $body);
        $misconfigured = in_array($response->status(), [401, 403, 404], true)
            || str_contains(strtolower($error), 'not allowed for quickstart')
            || str_contains(strtolower($error), 'not configured');

        Log::error('social.connect_refused', [
            'provider' => $provider,
            'platform' => $platform,
            'status' => $response->status(),
            'body' => $body,
            'misconfigured' => $misconfigured,
        ]);

        return new self($provider, $platform, $response->status(), mb_substr($error, 0, 300), $misconfigured);
    }

    public function render(): JsonResponse
    {
        $name = ucfirst($this->platform);

        return response()->json($this->misconfigured
            ? [
                'message' => "Connecting {$name} isn’t available right now — our team has been alerted. Please try again later.",
                'code' => 'provider_misconfigured',
                'provider_status' => $this->status,
            ]
            : [
                'message' => "{$name} didn’t accept the connection request: {$this->providerError}",
                'code' => 'provider_connect_refused',
                'provider_status' => $this->status,
            ], 422);
    }
}
