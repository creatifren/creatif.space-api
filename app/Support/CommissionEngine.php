<?php

namespace App\Support;

use App\Enums\CommissionStatus;
use App\Models\Affiliate;
use App\Models\Commission;
use App\Models\Invoice;
use App\Models\Referral;
use Illuminate\Database\QueryException;

/**
 * The only thing that writes `commissions`.
 *
 * Called from the Midtrans webhook when a subscription invoice is paid —
 * commission is earned on subscription payments, not on creator sales, and
 * the money is not credited here either. Twelve rows are written now and
 * released one a month by ReleaseCommissions, each after its holding
 * period, which is when the balance actually moves.
 */
final class CommissionEngine
{
    /**
     * A subscription bill was paid. Earn on it, if anyone is owed.
     */
    public static function forInvoice(Invoice $invoice): void
    {
        $referral = self::referralFor($invoice);

        if ($referral === null || ! $referral->affiliate->isApproved()) {
            return;
        }

        // The promise is twelve months of their payments, so a renewal in
        // month fourteen earns nothing.
        if (! $referral->isEarning()) {
            return;
        }

        if ($referral->first_paid_at === null) {
            // The cookie governs whether the *first* payment counts. After
            // that the twelve-month window takes over, so somebody who
            // signed up on day 89 and paid on day 100 still earns — the
            // cookie's job was to attribute the signup, and it did.
            if ($referral->cookie_expires_at->isPast() && $referral->signed_up_at === null) {
                return;
            }

            self::countFirstPayment($referral);
        }

        self::write($referral, $invoice);
    }

    /**
     * A refund inside the holding window: the money never landed, so the
     * rows go to `reversed` and no ledger line is written.
     *
     * ponytail: already-released commission is not clawed back. A negative
     * ledger line that could overdraw someone is a support conversation,
     * not an automation. Add a RefundDebit and a can-go-negative policy
     * when the first real refund happens.
     */
    public static function reverseForInvoice(Invoice $invoice): void
    {
        Commission::query()
            ->where('invoice_id', $invoice->id)
            ->where('status', CommissionStatus::Holding)
            ->update([
                'status' => CommissionStatus::Reversed,
                'updated_at' => now(),
            ]);
    }

    /**
     * The referral this invoice's subscriber came from, if any. Most
     * invoices have none, and that lookup is the cheap common case.
     */
    private static function referralFor(Invoice $invoice): ?Referral
    {
        $userId = $invoice->subscription?->user_id;

        if ($userId === null) {
            return null;
        }

        return Referral::query()
            ->where('referred_user_id', $userId)
            ->with('affiliate')
            ->first();
    }

    /**
     * First payment from this person: start their twelve-month window and
     * re-read the affiliate's tier, which may have just moved.
     */
    private static function countFirstPayment(Referral $referral): void
    {
        $referral->forceFill(['first_paid_at' => now()])->save();

        $affiliate = $referral->affiliate;
        $count = $affiliate->paid_referrals_count + 1;

        $affiliate->forceFill([
            'paid_referrals_count' => $count,
            'tier_percent' => number_format(Affiliate::tierFor($count), 2, '.', ''),
        ])->save();

        $referral->setRelation('affiliate', $affiliate->fresh());
    }

    /**
     * Twelve rows, one a month, each held thirty days from the payment
     * that earned it.
     */
    private static function write(Referral $referral, Invoice $invoice): void
    {
        $tier = (float) $referral->affiliate->tier_percent;
        $total = (int) floor($invoice->amount * $tier / 100);
        $each = intdiv($total, Affiliate::INSTALMENTS);
        // The remainder rides on the first instalment, so the twelve parts
        // add back to exactly the commission.
        $remainder = $total - $each * Affiliate::INSTALMENTS;

        if ($total <= 0) {
            return;
        }

        foreach (range(1, Affiliate::INSTALMENTS) as $part) {
            try {
                Commission::query()->create([
                    'referral_id' => $referral->id,
                    'invoice_id' => $invoice->id,
                    'amount' => $part === 1 ? $each + $remainder : $each,
                    'tier_percent' => $referral->affiliate->tier_percent,
                    'installment_no' => $part,
                    'hold_until' => now()
                        ->addDays(Affiliate::HOLD_DAYS)
                        ->addMonthsNoOverflow($part - 1),
                ]);
            } catch (QueryException $e) {
                // The unique (invoice_id, installment_no) refused a repeat.
                // Midtrans retries by contract, so this is the expected
                // path on a replayed notification, not an error.
                return;
            }
        }
    }
}
