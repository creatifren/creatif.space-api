<?php

namespace App\Support;

use App\Enums\InvoiceStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Creem says a subscription is trialing (card on file, nothing charged):
 * the plan's features until current_period_end_date, which is the first
 * charge — or, cancelled meanwhile, the day it goes back to Free.
 *
 * One path for every way we learn it: subscription.trialing, the $0
 * subscription.paid Creem sends at the trial's start, checkout.completed
 * carrying a trialing subscription, and `billing:reconcile-trials`.
 * Idempotent: trial_started fires once. The caller holds the row locks.
 *
 * Trials were removed on 13 Nov 2026: no checkout starts one. This keeps
 * the trials started before then working until they convert or end.
 */
class CreemTrial
{
    /**
     * @param  array<string, mixed>  $remote  Creem's subscription object
     *
     * @throws ValidationException when the object does not hold a trial period
     */
    public static function start(Subscription $subscription, array $remote): bool
    {
        // Late: the first real charge already landed, or the trial already
        // ended (a fresh checkout is Expired too, but has no period yet).
        // Active without a real charge is a trial recorded as paid before
        // trials were understood (its "payment" was Creem's $0 card check).
        if (in_array($subscription->status, [SubscriptionStatus::PastDue, SubscriptionStatus::Cancelled], true)
            || ($subscription->status === SubscriptionStatus::Expired && $subscription->current_period_start !== null)
            || self::charged($subscription)) {
            return false;
        }

        [$plan, $period] = CreemProducts::plan(CreemProducts::id($remote['product'] ?? null));
        $start = self::date($remote['current_period_start_date'] ?? null);
        $end = self::date($remote['current_period_end_date'] ?? null);
        if (! $end->gt($start)) {
            throw ValidationException::withMessages(['object' => 'Trial period ends before it starts.']);
        }
        if ($subscription->current_period_start !== null && $start->lt($subscription->current_period_start)) {
            return false;
        }

        $other = Subscription::query()->where('user_id', $subscription->user_id)
            ->whereKeyNot($subscription->id)
            ->whereIn('status', SubscriptionStatus::entitled())
            ->exists();
        if ($other) {
            Log::warning('billing.duplicate_subscription_trialing', [
                'subscription' => $subscription->ulid, 'creem_subscription' => $remote['id'] ?? null,
            ]);

            return false;
        }

        $already = $subscription->status === SubscriptionStatus::Trialing;
        $planBefore = ProductEvents::planOf($subscription->user);
        $subscription->update([
            'plan_id' => $plan->id, 'billing_period' => $period,
            'status' => SubscriptionStatus::Trialing,
            'creem_subscription_id' => $remote['id'] ?? $subscription->creem_subscription_id,
            'creem_customer_id' => CreemProducts::id($remote['customer'] ?? null) ?? $subscription->creem_customer_id,
            'current_period_start' => $start, 'current_period_end' => $end,
            'grace_ends_at' => null,
        ]);
        if (! $already) {
            ProductEvents::trialStarted($subscription->user, $plan->key, $period, max(1, (int) round($start->diffInDays($end, true))));
        }
        ProductEvents::planChanged($subscription->user, $planBefore);

        return ! $already;
    }

    /**
     * Has real money been taken? A $0 "paid" invoice is the card check that
     * opens a Creem trial, not a charge.
     */
    public static function charged(Subscription $subscription): bool
    {
        return $subscription->invoices()
            ->where('status', InvoiceStatus::Paid)
            ->where('amount', '>', 0)
            ->exists();
    }

    private static function date(mixed $value): CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            throw ValidationException::withMessages(['object' => 'Missing trial period.']);
        }

        return CarbonImmutable::parse($value);
    }
}
