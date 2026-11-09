<?php

namespace App\Jobs;

use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Models\User;
use App\Services\CreemService;
use App\Support\ProductEvents;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/** A missed-webhook safety net; Creem, not our clock, owns billing state. */
class ExpireSubscriptions implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [60, 300];

    public function handle(CreemService $creem): void
    {
        // Grace is ours to close: a card Creem keeps retrying must not keep
        // the plan forever.
        $lapsing = Subscription::query()
            ->where('status', SubscriptionStatus::PastDue)
            ->whereNotNull('grace_ends_at')
            ->where('grace_ends_at', '<=', now());
        $users = User::query()->whereIn('id', (clone $lapsing)->select('user_id'))->get();
        $lapsing->update(['status' => SubscriptionStatus::Expired]);
        $users->each(fn (User $u) => ProductEvents::planChanged($u));

        Subscription::query()
            ->whereIn('status', [SubscriptionStatus::Active, SubscriptionStatus::PastDue])
            ->whereNotNull('creem_subscription_id')
            ->where('current_period_end', '<', now()->subDays(3))
            ->lazyById()
            ->each(function (Subscription $subscription) use ($creem): void {
                $remote = $creem->subscription($subscription->creem_subscription_id);
                if (($remote['id'] ?? null) !== $subscription->creem_subscription_id) {
                    throw new \RuntimeException('Creem returned a different subscription.');
                }
                // Do not silently mint a paid period from a status read.
                // Operators can replay the missing paid event from Creem.
                Log::warning('billing.subscription_needs_reconciliation', [
                    'subscription' => $subscription->ulid,
                    'creem_status' => $remote['status'] ?? null,
                ]);
                if (in_array($remote['status'] ?? null, ['expired', 'unpaid', 'canceled'], true)) {
                    $subscription->update(['status' => SubscriptionStatus::Expired]);
                    ProductEvents::planChanged($subscription->user);
                }
            });
    }
}
