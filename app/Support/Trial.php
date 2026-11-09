<?php

namespace App\Support;

use App\Enums\InvoiceStatus;
use App\Enums\PlanKey;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * The 7-day Pro trial. It lives in Creem, on the Pro products themselves
 * (trial period 7 days, card taken, nothing charged until day 7); Max has
 * none. Our part is who may have it: one per workspace owner, derived —
 * no column:
 *
 * - never a subscription Creem knew about (a trial or a paid plan both
 *   carry creem_subscription_id; an abandoned checkout does not), and
 * - never a paid invoice.
 *
 * An owner who is not eligible is sent to the no-trial Pro twin.
 */
class Trial
{
    public static function days(): int
    {
        return (int) config('services.creem.trial_days', 7);
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

    /** Pro without a trial for this period, or null when not configured. */
    public static function noTrialProduct(string $period): ?string
    {
        $id = config("services.creem.products.pro_{$period}_notrial");

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * The no-trial Pro products, by period.
     *
     * @return array<string, string>
     */
    public static function noTrialProducts(): array
    {
        return array_filter([
            'monthly' => self::noTrialProduct('monthly'),
            'yearly' => self::noTrialProduct('yearly'),
        ]);
    }

    /**
     * The product a first checkout uses, and whether Creem will start it
     * with a trial.
     *
     * @return array{product: string|null, trial: bool}
     */
    public static function checkoutProduct(Plan $plan, string $period, User $user): array
    {
        if ($plan->key !== PlanKey::Pro) {
            return ['product' => $plan->creemProduct($period), 'trial' => false];
        }

        if (self::eligible($user)) {
            return ['product' => $plan->creemProduct($period), 'trial' => true];
        }

        $noTrial = self::noTrialProduct($period);
        if ($noTrial !== null) {
            return ['product' => $noTrial, 'trial' => false];
        }

        Log::warning('billing.repeat_trial_possible', [
            'user' => Workspace::owner($user)->ulid,
            'period' => $period,
            'missing' => 'CREEM_PRODUCT_PRO_'.strtoupper($period).'_NOTRIAL',
        ]);

        return ['product' => $plan->creemProduct($period), 'trial' => true];
    }

    /** A plan change for someone already paying never starts a trial. */
    public static function changeProduct(Plan $plan, string $period): ?string
    {
        return ($plan->key === PlanKey::Pro ? self::noTrialProduct($period) : null)
            ?? $plan->creemProduct($period);
    }
}
