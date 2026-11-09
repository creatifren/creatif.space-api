<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\InvoiceStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\WalletTransactionType;
use App\Http\Controllers\Controller;
use App\Models\CreditPurchase;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Notifications\BillingNeedsReview;
use App\Notifications\SubscriptionPastDue;
use App\Notifications\SubscriptionStarted;
use App\Services\CreemService;
use App\Support\CommissionEngine;
use App\Support\CreemProducts;
use App\Support\CreemTrial;
use App\Support\ProductEvents;
use App\Support\Wallet;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/** Only verified provider evidence settles money; redirects never do. */
class CreemWebhookController extends Controller
{
    public function __invoke(Request $request, CreemService $creem): JsonResponse
    {
        if (! $creem->verifySignature($request->getContent(), (string) $request->header('creem-signature'))) {
            return response()->json(['message' => 'Invalid signature.'], 403);
        }

        $event = $request->validate([
            'id' => ['required', 'string', 'max:64'],
            'eventType' => ['required', 'string', 'max:64'],
            'object' => ['required', 'array'],
        ]);

        // The receipt and every business write commit together. A failed
        // handler must not poison the event id and make its retry a no-op.
        DB::transaction(function () use ($event, $creem): void {
            if (! DB::table('webhook_events')->insertOrIgnore([
                'source' => 'creem', 'event_id' => $event['id'], 'type' => $event['eventType'],
                'payload' => json_encode($event, JSON_THROW_ON_ERROR),
                'created_at' => now(), 'updated_at' => now(),
            ])) {
                return;
            }

            $object = $event['object'];
            match ($event['eventType']) {
                'checkout.completed' => $this->checkout($object),
                'subscription.paid' => $this->paid($object, $creem),
                'subscription.trialing' => $this->trialing($object),
                'subscription.past_due', 'subscription.unpaid', 'subscription.expired',
                'subscription.canceled', 'subscription.scheduled_cancel' => $this->lifecycle($event['eventType'], $object),
                // A product update is not proof a proration charge succeeded.
                // Paid events alone grant upgraded features.
                'subscription.update', 'subscription.active' => null,
                'refund.created' => $this->refund($event),
                'dispute.created' => $this->dispute($event),
                default => null,
            };
        });

        return response()->json(['message' => 'ok']);
    }

    private function checkout(array $object): void
    {
        $requestId = $object['request_id'] ?? '';
        $purchase = CreditPurchase::query()->where('ulid', $requestId)->lockForUpdate()->first();

        if ($purchase !== null) {
            $order = $object['order'] ?? [];
            // What was actually paid, not the list price: a coupon on the
            // Credits product must not buy full Credits for less. Tax sits on
            // top under inclusive pricing, so paid may meet but not undercut.
            $paid = $order['amount_paid'] ?? $order['amount'] ?? null;
            $this->require(
                ($order['status'] ?? '') === 'paid'
                && ($order['currency'] ?? '') === 'USD'
                && ($order['amount'] ?? null) === $purchase->amount
                && is_int($paid) && $paid >= $purchase->amount
                && (int) ($order['discount_amount'] ?? 0) === 0
                && $this->id($object['product'] ?? null) === config('services.creem.products.credits')
                && ($purchase->creem_checkout_id === null || $purchase->creem_checkout_id === ($object['id'] ?? null)),
            );

            if ($purchase->status === 'paid') {
                return;
            }
            $this->require($purchase->status === 'pending');
            User::query()->whereKey($purchase->user_id)->lockForUpdate()->firstOrFail();
            $purchase->update([
                'status' => 'paid', 'paid_at' => now(), 'creem_checkout_id' => $object['id'],
                'raw_notification' => $object,
            ]);
            Wallet::credit($purchase->user, WalletTransactionType::CreditsTopUp, $purchase->credits,
                'credit_purchase', $purchase->id, 'Creem checkout '.$object['id'], Wallet::CREDITS);
            // Once: a replay finds the purchase paid and returns above.
            ProductEvents::creditsPurchased($purchase->user, (int) $purchase->amount, (int) $purchase->credits);

            return;
        }

        $invoice = Invoice::query()->where('number', $requestId)->lockForUpdate()->firstOrFail();
        $subscription = $invoice->subscription()->lockForUpdate()->firstOrFail();
        $remoteId = $this->id($object['subscription'] ?? null);
        $period = $subscription->billing_period->value;
        $this->require($remoteId !== null
            && ($subscription->creem_subscription_id === null || $subscription->creem_subscription_id === $remoteId)
            && in_array($this->id($object['product'] ?? null), CreemProducts::accepted($subscription->plan, $period), true));
        $subscription->update([
            'creem_subscription_id' => $remoteId,
            'creem_customer_id' => $this->id($object['customer'] ?? null),
        ]);
        $invoice->update(['creem_checkout_id' => $object['id']]);

        // The expanded subscription may already be trialing: start it here
        // too, so a lost subscription.trialing still grants the trial.
        $remote = $object['subscription'] ?? null;
        if (is_array($remote) && ($remote['status'] ?? null) === 'trialing') {
            $this->trialing($remote + ['product' => $object['product'] ?? null, 'customer' => $object['customer'] ?? null]);
        }
    }

    /**
     * subscription.trialing: see CreemTrial.
     *
     * @param  array<string, mixed>  $object
     */
    private function trialing(array $object): void
    {
        $subscription = $this->localSubscription($object);
        User::query()->whereKey($subscription->user_id)->lockForUpdate()->firstOrFail();
        CreemTrial::start($subscription, $object);
    }

    private function paid(array $object, CreemService $creem): void
    {
        $subscription = $this->localSubscription($object);
        User::query()->whereKey($subscription->user_id)->lockForUpdate()->firstOrFail();
        $transactionId = $object['last_transaction_id'] ?? null;
        $this->require(is_string($transactionId) && $transactionId !== '');
        if (Invoice::query()->where('creem_transaction_id', $transactionId)->exists()) {
            return;
        }

        $transaction = $object['last_transaction'] ?? $creem->transaction($transactionId);
        // Creem's trial opens with a $0 "paid" transaction (the card check).
        // It is not the first payment: it starts the trial, or — not
        // trialing — is recorded nowhere. Never a 422, or Creem retries it
        // forever and the trial never shows.
        if (($transaction['amount'] ?? null) === 0) {
            if (($object['status'] ?? null) === 'trialing') {
                CreemTrial::start($subscription, $object);
            } else {
                Log::info('billing.zero_paid_ignored', ['subscription' => $subscription->ulid, 'transaction' => $transactionId]);
            }

            return;
        }
        $this->require(($transaction['id'] ?? '') === $transactionId
            && ($transaction['currency'] ?? '') === 'USD'
            && in_array($transaction['status'] ?? '', ['paid', 'completed'], true)
            && is_int($transaction['amount'] ?? null) && $transaction['amount'] > 0);

        [$plan, $period] = $this->plan($this->id($object['product'] ?? null));
        $start = CarbonImmutable::parse($object['current_period_start_date'] ?? throw ValidationException::withMessages(['object' => 'Missing billing period.']));
        $end = CarbonImmutable::parse($object['current_period_end_date'] ?? throw ValidationException::withMessages(['object' => 'Missing billing period.']));
        $this->require($end->gt($start));
        // Two checkouts both paid: record the money, but never run two plans.
        // The second is left for an operator to refund in Creem.
        $other = Subscription::query()->where('user_id', $subscription->user_id)
            ->whereKeyNot($subscription->id)
            ->whereIn('status', SubscriptionStatus::entitled())
            ->exists();
        if ($other) {
            Log::warning('billing.duplicate_subscription_paid', [
                'subscription' => $subscription->ulid, 'creem_subscription' => $object['id'],
            ]);
        }

        $first = ! $subscription->invoices()->where('status', InvoiceStatus::Paid)->exists();
        $planBefore = ProductEvents::planOf($subscription->user);
        $invoice = $subscription->invoices()->where('status', InvoiceStatus::Pending)->oldest('id')->lockForUpdate()->first();
        if ($invoice === null) {
            $invoice = $subscription->invoices()->create(['number' => Invoice::nextNumber(), 'amount' => $transaction['amount'], 'currency' => 'USD']);
        }
        $invoice->update([
            'status' => InvoiceStatus::Paid, 'amount' => $transaction['amount'], 'currency' => 'USD',
            'creem_transaction_id' => $transactionId, 'paid_at' => now(), 'raw_notification' => $object,
        ]);

        // Record a late, older payment, but do not roll a newer period back.
        // Ordered by period start, not end: switching yearly → monthly makes
        // the new period end earlier while still being the newer one.
        if (! $other && ($subscription->current_period_start === null || $start->gte($subscription->current_period_start))) {
            $subscription->update([
                'plan_id' => $plan->id, 'billing_period' => $period,
                'status' => SubscriptionStatus::Active,
                'creem_subscription_id' => $object['id'],
                'creem_customer_id' => $this->id($object['customer'] ?? null),
                'current_period_start' => $start, 'current_period_end' => $end,
                'grace_ends_at' => null,
            ]);
        }
        if ($other) {
            return;
        }
        CommissionEngine::forInvoice($invoice);
        // Once per subscription: a replayed or later paid event finds a paid invoice.
        if ($first) {
            ProductEvents::subscriptionStarted($subscription->user, $plan->key, $period, (int) $transaction['amount']);
        }
        ProductEvents::planChanged($subscription->user, $planBefore);
        if ($first) {
            DB::afterCommit(fn () => $subscription->user->notify(new SubscriptionStarted($subscription->fresh()->load('plan'), $invoice)));
        }
    }

    private function lifecycle(string $type, array $object): void
    {
        $subscription = $this->localSubscription($object);
        // A delayed event about an older period must not undo a newer payment.
        if (isset($object['current_period_start_date']) && $subscription->current_period_start !== null
            && CarbonImmutable::parse($object['current_period_start_date'])->lt($subscription->current_period_start)) {
            return;
        }
        $planBefore = ProductEvents::planOf($subscription->user);
        if (in_array($type, ['subscription.scheduled_cancel', 'subscription.canceled'], true)) {
            // Once: the cancel endpoint or an earlier event may have recorded it.
            if ($subscription->cancelled_at === null) {
                ProductEvents::subscriptionCanceled($subscription->user, $subscription->plan->key);
            }
            $subscription->update(['cancelled_at' => $subscription->cancelled_at ?? now()]);
            if ($type === 'subscription.canceled' && $subscription->current_period_end?->isPast()) {
                $subscription->update(['status' => SubscriptionStatus::Expired]);
            }
        } elseif ($type === 'subscription.past_due') {
            if ($subscription->status !== SubscriptionStatus::PastDue) {
                // A fixed window: Creem retries the card meanwhile, and the
                // expiry job ends access once it closes, whatever Creem says.
                $subscription->update([
                    'status' => SubscriptionStatus::PastDue,
                    'grace_ends_at' => now()->addDays(Subscription::GRACE_DAYS),
                ]);
                DB::afterCommit(fn () => $subscription->user->notify(new SubscriptionPastDue($subscription)));
            }
        } else {
            $subscription->update(['status' => SubscriptionStatus::Expired]);
        }
        ProductEvents::planChanged($subscription->user, $planBefore);
    }

    private function localSubscription(array $object): Subscription
    {
        $remoteId = $object['id'] ?? null;
        $this->require(is_string($remoteId) && $remoteId !== '');
        $subscription = Subscription::query()->where('creem_subscription_id', $remoteId)->lockForUpdate()->first();
        // subscription.paid can arrive before checkout.completed. Creem
        // carries checkout metadata onto the subscription for this case.
        if ($subscription === null && is_string($object['metadata']['subscription'] ?? null)) {
            $subscription = Subscription::query()->where('ulid', $object['metadata']['subscription'])->lockForUpdate()->first();
        }
        abort_if($subscription === null, 404, 'Unknown subscription.');
        $this->require($subscription->creem_subscription_id === null || $subscription->creem_subscription_id === $remoteId);

        return $subscription;
    }

    /** @return array{0: Plan, 1: string} */
    private function plan(?string $product): array
    {
        return CreemProducts::plan($product);
    }

    /**
     * A refund settles what our side can settle by itself and hands the rest
     * to staff: a partial subscription refund, Credits already spent.
     */
    private function refund(array $event): void
    {
        $object = $event['object'];
        $transaction = is_array($object['transaction'] ?? null) ? $object['transaction'] : [];
        // Cumulative across refunds of the same payment, so a second partial
        // refund and a replayed one both settle from the running total.
        $refunded = $transaction['refunded_amount'] ?? $object['refund_amount'] ?? null;
        $paid = $transaction['amount_paid'] ?? $transaction['amount'] ?? null;
        $this->require(($object['status'] ?? '') === 'succeeded'
            && ($object['refund_currency'] ?? '') === 'USD'
            && is_int($refunded) && $refunded > 0 && is_int($paid) && $paid > 0);
        $full = $refunded >= $paid;

        $invoice = is_string($transaction['id'] ?? null)
            ? Invoice::query()->where('creem_transaction_id', $transaction['id'])->lockForUpdate()->first()
            : null;
        if ($invoice !== null) {
            if ($invoice->status === InvoiceStatus::Refunded) {
                return;
            }
            if (! $full) {
                BillingNeedsReview::staff($event['eventType'], $event['id'], "Partial refund on invoice {$invoice->number}: plan and commission left as they are.");

                return;
            }
            $invoice->update(['status' => InvoiceStatus::Refunded]);
            CommissionEngine::reverseForInvoice($invoice);

            return;
        }

        $checkout = is_array($object['checkout'] ?? null) ? $object['checkout'] : [];
        $purchase = CreditPurchase::query()
            ->where('ulid', (string) ($checkout['request_id'] ?? ''))
            ->lockForUpdate()->first();
        if ($purchase === null || $purchase->status === 'pending') {
            BillingNeedsReview::staff($event['eventType'], $event['id'], 'Refund matches no paid invoice or Credits purchase.');

            return;
        }
        if ($purchase->status === 'refunded') {
            return;
        }
        $user = User::query()->whereKey($purchase->user_id)->lockForUpdate()->firstOrFail();
        // The refunded share of what was bought, less what earlier refunds
        // already took. Credits already spent stay spent: the ledger never
        // goes negative, staff settle the rest.
        $share = $full ? $purchase->credits : intdiv($purchase->credits * $refunded, $paid);
        $owed = max(0, $share + (int) WalletTransaction::query()
            ->where('reference_type', 'credit_purchase')->where('reference_id', $purchase->id)
            ->where('type', WalletTransactionType::CreditsRefund)->sum('amount'));
        $taken = min($owed, Wallet::balance($user, Wallet::CREDITS));
        if ($taken > 0) {
            Wallet::debit($user, WalletTransactionType::CreditsRefund, $taken,
                'credit_purchase', $purchase->id, 'Creem refund '.$object['id'], Wallet::CREDITS);
        }
        if ($full) {
            $purchase->update(['status' => 'refunded']);
        }
        if ($taken < $owed) {
            BillingNeedsReview::staff($event['eventType'], $event['id'],
                'Refunded Credits purchase '.$purchase->ulid.': '.($owed - $taken).' Credits were already spent.');
        }
    }

    /** A dispute is the bank's call; Creem fights it, staff decide the rest. */
    private function dispute(array $event): void
    {
        Log::warning('billing.dispute', ['event_id' => $event['id']]);
        BillingNeedsReview::staff($event['eventType'], $event['id'], 'A customer opened a dispute in Creem.');
    }

    private function id(mixed $value): ?string
    {
        return is_string($value) ? $value : (is_array($value) && is_string($value['id'] ?? null) ? $value['id'] : null);
    }

    private function require(bool $condition): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['object' => 'Payment evidence does not match the purchase.']);
        }
    }
}
