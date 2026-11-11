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

/** A missed-webhook safety net; Creem and Google Play, not our clock, own billing state. */
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

        // A trial cancelled before it ended goes back to Free when it would
        // have charged. Nothing was paid, so there is nothing to ask Creem.
        $endedTrials = Subscription::query()
            ->where('status', SubscriptionStatus::Trialing)
            ->whereNotNull('cancelled_at')
            ->whereNotNull('current_period_end')
            ->where('current_period_end', '<=', now());
        $trialUsers = User::query()->whereIn('id', (clone $endedTrials)->select('user_id'))->get();
        $endedTrials->update(['status' => SubscriptionStatus::Expired]);
        $trialUsers->each(fn (User $u) => ProductEvents::planChanged($u));

        // Google Play: notifications move these rows, so this is the net for
        // one that never arrived. A cancelled Play plan ends with its period;
        // any other ends once the period plus the grace window is over (a
        // renewal would have moved current_period_end). Past-due Play rows
        // close with the grace block above, like Creem's.
        $playEnded = Subscription::query()
            ->where('provider', Subscription::PROVIDER_GOOGLE)
            ->whereNotNull('current_period_end')
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->where('status', SubscriptionStatus::Cancelled)
                    ->where('current_period_end', '<=', now()))
                ->orWhere(fn ($q) => $q->whereIn('status', [SubscriptionStatus::Active, SubscriptionStatus::Trialing])
                    ->where('current_period_end', '<=', now()->subDays(Subscription::GRACE_DAYS))));
        $playUsers = User::query()->whereIn('id', (clone $playEnded)->select('user_id'))->get();
        $playEnded->update(['status' => SubscriptionStatus::Expired, 'grace_ends_at' => null]);
        $playUsers->each(fn (User $u) => ProductEvents::planChanged($u));

        Subscription::query()
            ->whereIn('status', SubscriptionStatus::entitled())
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
