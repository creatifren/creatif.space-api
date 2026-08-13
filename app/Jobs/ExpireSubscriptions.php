<?php

namespace App\Jobs;

use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Notifications\SubscriptionPastDue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The daily sweep that moves lapsed subscriptions along.
 *
 *   active, period over        → past_due, 7 days of grace, tell them
 *   past_due, grace over       → expired (back to Free)
 *   cancelled, period over     → expired, quietly: they asked for this
 *
 * Downgrade never deletes anything. Spaces above the Free ceiling stay and
 * stay readable — only making or publishing new ones stops, which is what
 * PlanQuota already enforces at the create and publish gates.
 */
class ExpireSubscriptions implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function handle(): void
    {
        $this->startGrace();
        $this->endGrace();
    }

    /**
     * A period that ran out. Cancelled ones go straight to expired — there
     * is nothing to chase, they told us to stop.
     */
    private function startGrace(): void
    {
        $lapsed = Subscription::query()
            ->where('status', SubscriptionStatus::Active)
            ->whereNotNull('current_period_end')
            ->where('current_period_end', '<=', now())
            ->with(['plan', 'user'])
            ->get();

        foreach ($lapsed as $subscription) {
            if ($subscription->cancelled_at !== null) {
                $subscription->forceFill([
                    'status' => SubscriptionStatus::Expired,
                ])->save();

                continue;
            }

            $subscription->forceFill([
                'status' => SubscriptionStatus::PastDue,
                'grace_ends_at' => now()->addDays(Subscription::GRACE_DAYS),
            ])->save();

            $subscription->user->notify(new SubscriptionPastDue($subscription));
        }
    }

    /**
     * Grace is over: back to Free.
     */
    private function endGrace(): void
    {
        Subscription::query()
            ->where('status', SubscriptionStatus::PastDue)
            ->whereNotNull('grace_ends_at')
            ->where('grace_ends_at', '<=', now())
            ->update(['status' => SubscriptionStatus::Expired]);
    }
}
