<?php

namespace App\Services;

use App\Jobs\SendAnalytics;
use App\Models\User;
use Illuminate\Support\Facades\Http;
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
     * A server-side \$exception for PostHog error tracking. Unlike capture()
     * it is not held until a transaction commits: the transaction that
     * failed is usually the one being rolled back.
     *
     * @param  array<string, mixed>  $properties
     */
    public function captureException(string $distinctId, array $properties): void
    {
        $this->dispatch(['type' => 'capture', 'distinct_id' => $distinctId, 'event' => '$exception', 'properties' => $properties], afterCommit: false);
    }

    /**
     * Delete the person (and their events) from PostHog — account deletion.
     * Needs the private API: POSTHOG_PERSONAL_API_KEY with person:write and
     * POSTHOG_PROJECT_ID; without them it is skipped and logged. Never
     * throws: the purge goes on either way.
     */
    public function deletePerson(string $distinctId): void
    {
        $key = (string) config('services.posthog.personal_api_key');
        $project = (string) config('services.posthog.project_id');
        if ($key === '' || $project === '') {
            Log::warning('posthog.person_delete_skipped', ['reason' => 'POSTHOG_PERSONAL_API_KEY or POSTHOG_PROJECT_ID not set']);

            return;
        }

        try {
            $base = rtrim((string) config('services.posthog.app_host'), '/')."/api/projects/{$project}/persons";
            $client = Http::withToken($key)->timeout(10);
            $person = $client->get($base.'/', ['distinct_id' => $distinctId])->throw()->json('results.0.id');
            if (is_string($person) && $person !== '') {
                $client->delete($base.'/'.rawurlencode($person).'/', ['delete_events' => 'true'])->throw();
            }
        } catch (Throwable $e) {
            Log::warning('posthog.person_delete_failed', ['error' => $e->getMessage()]);
        }
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
        // Staff are internal whatever their address (raw read: a model built
        // in memory may not carry the column default).
        if ((bool) ($user->getAttributes()['is_staff'] ?? false)) {
            return true;
        }

        return self::isInternalEmail((string) ($user->getAttributes()['email'] ?? ''));
    }

    /** Our own inbox, always, plus ANALYTICS_INTERNAL_EMAILS and _DOMAINS. */
    public const ALWAYS_INTERNAL = ['hello@creatif.space'];

    public static function isInternalEmail(string $email): bool
    {
        $email = Str::lower(trim($email));
        if ($email === '') {
            return false;
        }

        if (in_array($email, [...self::ALWAYS_INTERNAL, ...(array) config('services.posthog.internal_emails', [])], true)) {
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
    protected function dispatch(array $message, bool $afterCommit = true): void
    {
        if (! $this->enabled() || $message['distinct_id'] === '') {
            return;
        }

        try {
            $job = SendAnalytics::dispatch($message + ['timestamp' => now()->toIso8601String()]);
            if ($afterCommit) {
                $job->afterCommit();
            }
            // PendingDispatch sends on destruct: do it here, inside the try.
            unset($job);
        } catch (Throwable $e) {
            // A queue that is down must not take the request with it.
            Log::warning('analytics.dispatch_failed', ['event' => $message['event'] ?? '$set', 'error' => $e->getMessage()]);
        }
    }
}
