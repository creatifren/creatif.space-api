<?php

namespace App\Support;

use App\Enums\CommissionStatus;
use App\Enums\InvoiceStatus;
use App\Models\Affiliate;
use App\Models\Commission;
use App\Models\Invoice;
use App\Models\Referral;
use App\Models\Subscription;
use App\Notifications\BillingNeedsReview;
use App\Services\CreemService;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Before trial support, Creem's $0 card check that opens a trial was
 * recorded as a paid invoice at the plan's list price (prod: sub 11,
 * invoice 11, "$9.99 paid" — Creem's only payment on it is $0.00).
 *
 * For a subscription Creem says is trialing, every Paid invoice is checked
 * against the Creem transaction behind it, and corrected to what Creem
 * really took. An invoice whose transaction is a real charge (> $0) is
 * never touched — the whole subscription is then left alone.
 *
 * The corrected invoice stays `paid`: Creem did complete a $0 transaction,
 * and CreemTrial::charged() already reads a $0 Paid invoice as "not
 * charged". What the wrong amount set off is undone with it: holding
 * commission on that invoice is reversed, and an affiliate's first-payment
 * count goes back when this was the referral's only payment. Commission
 * already released to a wallet is not clawed back automatically — staff
 * are told.
 */
final class TrialInvoiceRepair
{
    /**
     * @return list<array{invoice: Invoice, from: int, to: int, transaction: string|null}>
     *
     * @throws RuntimeException when an invoice cannot be verified or Creem shows a real charge
     */
    public static function plan(Subscription $subscription, CreemService $creem): array
    {
        $paid = $subscription->invoices()->where('status', InvoiceStatus::Paid)->orderBy('id')->get();
        $corrections = [];
        $listed = null;
        $taken = [];

        foreach ($paid as $invoice) {
            if ($invoice->creem_transaction_id !== null) {
                $tx = $creem->transaction($invoice->creem_transaction_id);
                if (($tx['id'] ?? null) !== $invoice->creem_transaction_id) {
                    throw new RuntimeException("invoice {$invoice->number}: Creem returned no transaction {$invoice->creem_transaction_id}");
                }
            } else {
                $listed ??= array_values(array_filter(
                    $creem->customerTransactions((string) $subscription->creem_customer_id),
                    fn (array $t) => CreemProducts::id($t['subscription'] ?? null) === $subscription->creem_subscription_id,
                ));
                if ($listed === []) {
                    throw new RuntimeException("invoice {$invoice->number} has no Creem transaction id and Creem lists none for this subscription");
                }
                foreach ($listed as $t) {
                    if (self::taken($t) > 0) {
                        throw new RuntimeException("invoice {$invoice->number} has no Creem transaction id and Creem lists a real payment ({$t['id']}: ".self::usd(self::taken($t)).')');
                    }
                }
                $tx = collect($listed)->first(fn (array $t) => ! in_array($t['id'] ?? null, $taken, true)
                    && ! Invoice::query()->where('creem_transaction_id', $t['id'] ?? '')->exists());
                $tx ??= $listed[0];
            }

            $real = self::taken($tx);
            if ($real > 0) {
                throw new RuntimeException("invoice {$invoice->number}: Creem transaction {$tx['id']} is a real charge of ".self::usd($real));
            }
            $taken[] = $tx['id'] ?? null;
            if ((int) $invoice->amount !== $real) {
                $corrections[] = [
                    'invoice' => $invoice,
                    'from' => (int) $invoice->amount,
                    'to' => $real,
                    'transaction' => is_string($tx['id'] ?? null) ? $tx['id'] : null,
                ];
            }
        }

        return $corrections;
    }

    /**
     * What applying the plan would change, one line each.
     *
     * @param  list<array{invoice: Invoice, from: int, to: int, transaction: string|null}>  $corrections
     * @return list<string>
     */
    public static function describe(Subscription $subscription, array $corrections): array
    {
        $lines = [];
        foreach ($corrections as $c) {
            $invoice = $c['invoice'];
            $lines[] = "invoice {$invoice->number}: ".self::usd($c['from']).' → '.self::usd($c['to'])
                .' (Creem transaction '.($c['transaction'] ?? '?').($invoice->creem_transaction_id === null ? ', linked now' : '').')';
            $holding = Commission::query()->where('invoice_id', $invoice->id)->where('status', CommissionStatus::Holding);
            $released = Commission::query()->where('invoice_id', $invoice->id)->where('status', CommissionStatus::Released);
            if ($holding->exists()) {
                $lines[] = "  commission on {$invoice->number}: {$holding->count()} holding row(s), ".self::usd((int) $holding->sum('amount')).' → reversed';
            }
            if ($released->exists()) {
                $lines[] = "  commission on {$invoice->number}: ".self::usd((int) $released->sum('amount')).' already released to a wallet → staff told, not clawed back';
            }
        }
        if ($corrections !== [] && ($referral = self::referralToUndo($subscription, $corrections)) !== null) {
            $affiliate = $referral->affiliate;
            $count = $affiliate->paid_referrals_count;
            $lines[] = "  referral: first payment undone (affiliate {$affiliate->id}: paid referrals {$count} → ".max(0, $count - 1).')';
        }

        return $lines;
    }

    /**
     * Correct the invoices and undo what their wrong amount set off. The
     * caller holds the subscription and user locks, inside a transaction.
     *
     * @param  list<array{invoice: Invoice, from: int, to: int, transaction: string|null}>  $corrections
     */
    public static function apply(Subscription $subscription, array $corrections): void
    {
        $referral = self::referralToUndo($subscription, $corrections);

        foreach ($corrections as $c) {
            $invoice = Invoice::query()->whereKey($c['invoice']->id)->lockForUpdate()->firstOrFail();
            CommissionEngine::reverseForInvoice($invoice);
            $released = (int) Commission::query()->where('invoice_id', $invoice->id)
                ->where('status', CommissionStatus::Released)->sum('amount');
            if ($released > 0) {
                BillingNeedsReview::staff('trial_invoice_corrected', $invoice->number,
                    "Invoice {$invoice->number} was a $0 trial check recorded as ".self::usd($c['from'])
                    .'; '.self::usd($released).' commission on it was already released and was not clawed back.');
            }
            $invoice->forceFill([
                'amount' => $c['to'],
                'creem_transaction_id' => $invoice->creem_transaction_id ?? $c['transaction'],
            ])->save();
            Log::warning('billing.trial_invoice_corrected', [
                'subscription' => $subscription->ulid, 'invoice' => $invoice->number,
                'from' => $c['from'], 'to' => $c['to'], 'transaction' => $c['transaction'],
                'released_commission' => $released,
            ]);
        }

        if ($referral !== null) {
            $affiliate = Affiliate::query()->whereKey($referral->affiliate_id)->lockForUpdate()->firstOrFail();
            $count = max(0, $affiliate->paid_referrals_count - 1);
            $affiliate->forceFill([
                'paid_referrals_count' => $count,
                'tier_percent' => number_format(Affiliate::tierFor($count), 2, '.', ''),
            ])->save();
            $referral->forceFill(['first_paid_at' => null])->save();
        }
    }

    /**
     * The referral whose first payment was this wrong invoice: counted, and
     * the referred owner has no real payment left once these are corrected.
     *
     * @param  list<array{invoice: Invoice, from: int, to: int, transaction: string|null}>  $corrections
     */
    private static function referralToUndo(Subscription $subscription, array $corrections): ?Referral
    {
        if (array_filter($corrections, fn (array $c) => $c['from'] > 0 && $c['to'] === 0) === []) {
            return null;
        }
        $referral = Referral::query()->where('referred_user_id', $subscription->user_id)
            ->whereNotNull('first_paid_at')->with('affiliate')->first();
        if ($referral === null) {
            return null;
        }
        $corrected = array_map(fn (array $c) => $c['invoice']->id, $corrections);
        $otherReal = Invoice::query()
            ->whereIn('subscription_id', Subscription::query()->where('user_id', $subscription->user_id)->select('id'))
            ->where('status', InvoiceStatus::Paid)->where('amount', '>', 0)
            ->whereNotIn('id', $corrected)
            ->exists();

        return $otherReal ? null : $referral;
    }

    /** @param  array<string, mixed>  $tx */
    private static function taken(array $tx): int
    {
        return is_int($tx['amount_paid'] ?? null) ? $tx['amount_paid'] : (int) ($tx['amount'] ?? 0);
    }

    private static function usd(int $cents): string
    {
        return '$'.number_format($cents / 100, 2);
    }
}
