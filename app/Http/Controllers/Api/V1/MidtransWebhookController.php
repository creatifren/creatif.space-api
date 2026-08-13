<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\InvoiceStatus;
use App\Enums\OrderStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\WalletTransactionType;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Subscription;
use App\Notifications\OrderPaid;
use App\Notifications\SubscriptionStarted;
use App\Services\MidtransService;
use App\Support\Wallet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The only thing that may mark an invoice paid.
 *
 * Public and unauthenticated by necessity — Midtrans calls it, not a
 * browser. The signature is the authentication, so a bad one is refused
 * before anything is read.
 *
 * Idempotent by contract: Midtrans retries, and a settled invoice must
 * absorb a repeat notification without moving twice.
 */
class MidtransWebhookController extends Controller
{
    public function __invoke(Request $request, MidtransService $midtrans): JsonResponse
    {
        /** @var array<string, mixed> $payload */
        $payload = $request->all();

        if (! $midtrans->verifySignature($payload)) {
            return response()->json(['message' => 'Invalid signature.'], 403);
        }

        $orderId = (string) ($payload['order_id'] ?? '');

        // Two things get paid for here: a subscription bill (CS-…) and a
        // creator's sale (OR-…). The prefix says which, and the id is unique
        // either way, so a lookup can't cross the streams.
        if (str_starts_with($orderId, 'OR-')) {
            return $this->handleOrder($orderId, $payload, $midtrans);
        }

        $invoice = Invoice::query()
            ->where('midtrans_order_id', $orderId)
            ->with('subscription')
            ->first();

        if ($invoice === null) {
            return response()->json(['message' => 'Unknown order.'], 404);
        }

        // Already settled: record what arrived, change nothing. Answering
        // 200 stops Midtrans retrying a notification we have handled.
        if ($invoice->isSettled()) {
            $invoice->forceFill(['raw_notification' => $payload])->save();

            return response()->json(['message' => 'Already processed.']);
        }

        $outcome = $midtrans->outcome($payload);

        DB::transaction(function () use ($invoice, $payload, $outcome) {
            $invoice->forceFill([
                'status' => match ($outcome) {
                    'paid' => InvoiceStatus::Paid,
                    'failed' => InvoiceStatus::Failed,
                    'expired' => InvoiceStatus::Expired,
                    default => InvoiceStatus::Pending,
                },
                'payment_method' => $payload['payment_type'] ?? $invoice->payment_method,
                'paid_at' => $outcome === 'paid' ? now() : null,
                'raw_notification' => $payload,
            ])->save();

            if ($outcome === 'paid') {
                $this->activate($invoice->subscription);
            }
        });

        if ($outcome === 'paid') {
            $subscription = $invoice->subscription->fresh()->load('plan');
            $subscription->user->notify(new SubscriptionStarted($subscription, $invoice));
        }

        return response()->json(['message' => 'ok']);
    }

    /**
     * A creator's sale. The money is credited to their balance here and
     * nowhere else — and only once, however many times Midtrans retries.
     *
     * @param  array<string, mixed>  $payload
     */
    private function handleOrder(
        string $orderId,
        array $payload,
        MidtransService $midtrans,
    ): JsonResponse {
        $order = Order::query()
            ->where('midtrans_order_id', $orderId)
            ->with(['creator', 'offer', 'client'])
            ->first();

        if ($order === null) {
            return response()->json(['message' => 'Unknown order.'], 404);
        }

        if ($order->isSettled()) {
            $order->forceFill(['raw_notification' => $payload])->save();

            return response()->json(['message' => 'Already processed.']);
        }

        $outcome = $midtrans->outcome($payload);

        DB::transaction(function () use ($order, $payload, $outcome) {
            $order->forceFill([
                'status' => match ($outcome) {
                    'paid' => OrderStatus::Paid,
                    'failed' => OrderStatus::Failed,
                    'expired' => OrderStatus::Expired,
                    default => OrderStatus::Pending,
                },
                'payment_method' => $payload['payment_type'] ?? $order->payment_method,
                'paid_at' => $outcome === 'paid' ? now() : null,
                'raw_notification' => $payload,
            ])->save();

            if ($outcome === 'paid') {
                // net_amount, not amount: the platform fee never lands in
                // the creator's balance in the first place.
                Wallet::credit(
                    $order->creator,
                    WalletTransactionType::SaleCredit,
                    $order->net_amount,
                    'order',
                    $order->id,
                );
            }
        });

        if ($outcome === 'paid') {
            $order->creator->notify(new OrderPaid($order));
        }

        return response()->json(['message' => 'ok']);
    }

    /**
     * Money arrived: run the plan for a period from now. Also the renewal
     * path — a past_due subscription that finally paid picks up where the
     * old period ended, so nobody loses days by paying late.
     */
    private function activate(Subscription $subscription): void
    {
        $from = $subscription->current_period_end !== null
            && $subscription->current_period_end->isFuture()
                ? $subscription->current_period_end
                : now();

        $subscription->forceFill([
            'status' => SubscriptionStatus::Active,
            'current_period_start' => now(),
            'current_period_end' => $subscription->billing_period->value === 'yearly'
                ? $from->addYear()
                : $from->addMonth(),
            'grace_ends_at' => null,
        ])->save();
    }
}
