<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use PostHog\Client;
use Throwable;

/**
 * One PostHog call, off the request. Best effort: a failure is logged and
 * dropped, never retried into a pile-up and never surfaced to anyone.
 */
class SendAnalytics implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    /**
     * @param  array{type: string, distinct_id: string, event?: string, properties: array<string, mixed>, timestamp?: string}  $message
     */
    public function __construct(public array $message) {}

    public function handle(): void
    {
        $key = (string) config('services.posthog.key');
        if ($key === '') {
            return;
        }

        try {
            $client = $this->client($key);
            // When it happened, not when the worker got to it.
            $timestamp = $this->message['timestamp'] ?? now()->toIso8601String();

            if ($this->message['type'] === 'set') {
                $client->identify([
                    'distinctId' => $this->message['distinct_id'],
                    'properties' => $this->message['properties'],
                    'timestamp' => $timestamp,
                ]);
            } else {
                $client->capture([
                    'distinctId' => $this->message['distinct_id'],
                    'event' => (string) ($this->message['event'] ?? ''),
                    'properties' => $this->message['properties'],
                    'timestamp' => $timestamp,
                    // Server-side: the queue worker's IP says nothing about the user.
                    'disableGeoip' => true,
                ]);
            }

            $client->flush();
        } catch (Throwable $e) {
            Log::warning('analytics.send_failed', [
                'event' => $this->message['event'] ?? '$set',
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** Separate so a test can hand in a client that records. */
    protected function client(string $key): Client
    {
        return new Client($key, [
            'host' => (string) config('services.posthog.host'),
            'consumer' => 'lib_curl',
            'timeout' => 5000,
            // The library reports a failed request here rather than throwing.
            'error_handler' => fn ($code = null, $msg = null) => Log::warning('analytics.send_failed', [
                'event' => $this->message['event'] ?? '$set',
                'code' => is_scalar($code) ? $code : null,
                'error' => is_scalar($msg) ? $msg : 'request failed',
            ]),
        ], null, null, false);
    }
}
