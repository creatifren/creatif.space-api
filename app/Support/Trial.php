<?php

namespace App\Support;

use App\Enums\InvoiceStatus;
use App\Enums\PlanKey;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\User;

/**
 * The 7-day Pro trial: Creem's product-level trial (card required) on
 * separate "Pro … (7-day trial)" products, so the normal products stay
 * trial-free. One per workspace owner, derived — no column:
 *
 * - never a subscription Creem knew about (a trial or a paid plan both
 *   carry creem_subscription_id; an abandoned checkout does not), and
 * - never a paid invoice.
 */
class Trial
{
    public static function days(): int
    {
        return (int) config('services.creem.trial_days', 7);
    }

    /** The trial product for Pro in this period, or null when it is off. */
    public static function product(Plan|PlanKey $plan, string $period): ?string
    {
        $key = $plan instanceof Plan ? $plan->key : $plan;
        if ($key !== PlanKey::Pro) {
            return null;
        }

        $id = config("services.creem.products.pro_{$period}_trial");

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * Every configured trial product, mapped to its period.
     *
     * @return array<string, string>
     */
    public static function products(): array
    {
        return array_filter([
            'monthly' => self::product(PlanKey::Pro, 'monthly'),
            'yearly' => self::product(PlanKey::Pro, 'yearly'),
        ]);
    }

    /** This owner has never had a trial or a paid plan. */
    public static function eligible(User $user): bool
    {
        $owner = Workspace::owner($user);

        return ! $owner->subscriptions()->whereNotNull('creem_subscription_id')->exists()
            && ! Invoice::query()
                ->whereIn('subscription_id', $owner->subscriptions()->select('id'))
                ->where('status', InvoiceStatus::Paid)
                ->exists();
    }

    /** Offered at all: eligible, and a trial product is configured. */
    public static function offered(User $user): bool
    {
        return self::products() !== [] && self::eligible($user);
    }
}
