<?php

namespace App\Billing;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use Carbon\CarbonImmutable;

/**
 * What changing a running subscription to another plan or period charges,
 * worked out before anything is charged (bug b202611140813). Read-only.
 *
 * Creem moves the plan with update_behavior proration-charge-immediately:
 * the unused part of what was paid is credited and the new plan is charged
 * for the same span. So, in cents:
 *
 *   same period:   charge_now = (new price − paid price) × the share of the period left
 *                  renews on the same date, at the new price
 *   other period:  charge_now = new price − paid price × the share of the period left
 *                  a new period starts now; it renews a month or a year from now
 *
 * A negative result is never charged: it is `credit`, and charge_now is 0.
 * "Paid price" is the last paid bill of this period when there is one (a
 * Founding discount stays counted), else the plan's list price. Creem
 * computes the charge itself, so `estimated` is always true and the screen
 * says the receipt has the exact figure.
 */
final class PlanChangePreview
{
    /**
     * @return array{plan: array{key: string, name: string}, period: string, price: int, current: array{plan: array{key: string, name: string}, period: string, paid: int}, currency: string, charge_now: int, credit: int, renews_at: string|null, renews_amount: int, period_changes: bool, estimated: bool}
     */
    public static function for(Subscription $current, Plan $to, string $period, CarbonImmutable $now): array
    {
        $fromPeriod = $current->billing_period->value;
        $paid = self::paidPrice($current);
        $price = $to->priceFor($period);
        $left = self::shareLeft($current, $now);
        $samePeriod = $fromPeriod === $period;

        $raw = $samePeriod
            ? (int) round(($price - $paid) * $left)
            : $price - (int) round($paid * $left);

        $end = $current->current_period_end === null ? null : CarbonImmutable::make($current->current_period_end);
        $renews = $samePeriod
            ? $end
            : ($period === 'yearly' ? $now->addYearNoOverflow() : $now->addMonthNoOverflow());

        return [
            'plan' => ['key' => $to->key->value, 'name' => (string) $to->name],
            'period' => $period,
            'price' => $price,
            'current' => [
                'plan' => ['key' => $current->plan->key->value, 'name' => (string) $current->plan->name],
                'period' => $fromPeriod,
                'paid' => $paid,
            ],
            'currency' => 'USD',
            'charge_now' => max(0, $raw),
            'credit' => max(0, -$raw),
            'renews_at' => $renews?->toIso8601String(),
            'renews_amount' => $price,
            'period_changes' => ! $samePeriod,
            'estimated' => true,
        ];
    }

    /** The share of the current period still to run, 0…1. Unknown dates count as none left. */
    public static function shareLeft(Subscription $current, CarbonImmutable $now): float
    {
        $start = $current->current_period_start;
        $end = $current->current_period_end;
        if ($start === null || $end === null || ! $end->greaterThan($start)) {
            return 0.0;
        }
        $total = $end->getTimestamp() - $start->getTimestamp();
        $left = $end->getTimestamp() - $now->getTimestamp();

        return max(0.0, min(1.0, $left / $total));
    }

    /** What this period was paid: its last paid bill, else the plan's list price for the period. */
    public static function paidPrice(Subscription $current): int
    {
        $list = $current->plan->priceFor($current->billing_period->value);
        $start = $current->current_period_start;

        $bill = $current->invoices
            ->filter(fn (Invoice $i) => $i->status === InvoiceStatus::Paid
                && $i->paid_at !== null
                && ($start === null || $i->paid_at->greaterThanOrEqualTo($start->copy()->subDay())))
            ->sortByDesc('paid_at')
            ->first();

        // A bill above the list price is not this plan's price (a seat
        // change, a mistake): never credit more than the plan costs.
        return $bill !== null && $bill->amount > 0 && $bill->amount <= $list ? (int) $bill->amount : $list;
    }
}
