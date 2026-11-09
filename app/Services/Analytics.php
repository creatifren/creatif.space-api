<?php

namespace App\Services;

use App\Jobs\SendAnalytics;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Server-side product analytics (PostHog): the North Star (Weekly Active
 * Publishers) and the activation funnel.
 *
 * Three rules every caller can lean on:
 *  - It never blocks a request and never fails one. Each call is a queued
 *    job dispatched after the surrounding transaction commits (a rolled
 *    back signup is not a signup); the job swallows and logs its errors.
 *  - It is off unless POSTHOG_API_KEY is set, and always off under tests
 *    — tests swap in AnalyticsFake via Analytics::fake().
 *  - distinct_id is the user's ULID, never an email. Properties are ids,
 *    counts and enums: no captions, names, client names or addresses.
 */
class Analytics
{
    /** Swap in a recording fake for this test. */
    public static function fake(): AnalyticsFake
    {
        $fake = new AnalyticsFake;
        app()->instance(self::class, $fake);

        return $fake;
    }

    public function enabled(): bool
    {
        return (string) config('services.posthog.key') !== '' && ! app()->environment('testing');
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    public function capture(string $userId, string $event, array $properties = []): void
    {
        $this->dispatch(['type' => 'capture', 'distinct_id' => $userId, 'event' => $event, 'properties' => $properties]);
    }

    /**
     * Person properties ($set).
     *
     * @param  array<string, mixed>  $properties
     */
    public function set(string $userId, array $properties): void
    {
        $this->dispatch(['type' => 'set', 'distinct_id' => $userId, 'properties' => $properties]);
    }

    /**
     * Staff and test accounts, by address or domain from config — sent as a
     * property, not a decision: dashboards filter on it.
     */
    public static function isInternal(User $user): bool
    {
        $email = Str::lower((string) $user->email);
        if ($email === '') {
            return false;
        }

        if (in_array($email, (array) config('services.posthog.internal_emails', []), true)) {
            return true;
        }

        $domain = Str::after($email, '@');
        foreach ((array) config('services.posthog.internal_email_domains', []) as $internal) {
            if ($domain === $internal || str_ends_with($domain, '.'.$internal)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array{type: string, distinct_id: string, event?: string, properties: array<string, mixed>}  $message
     */
    protected function dispatch(array $message): void
    {
        if (! $this->enabled() || $message['distinct_id'] === '') {
            return;
        }

        try {
            SendAnalytics::dispatch($message + ['timestamp' => now()->toIso8601String()])->afterCommit();
        } catch (Throwable $e) {
            // A queue that is down must not take the request with it.
            Log::warning('analytics.dispatch_failed', ['event' => $message['event'] ?? '$set', 'error' => $e->getMessage()]);
        }
    }
}
