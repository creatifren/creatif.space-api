<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\InvoiceStatus;
use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\InvoiceResource;
use App\Http\Resources\PlanResource;
use App\Http\Resources\SubscriptionResource;
use App\Models\Invoice;
use App\Models\Plan;
use App\Services\MidtransService;
use App\Support\PlanQuota;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Settings → Subscription. Checkout opens a Midtrans Snap popup; nothing
 * here ever marks an invoice paid — only the webhook does that.
 */
class SubscriptionController extends Controller
{
    /**
     * The current plan, its period, and the last bill.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $subscription = $user->activeSubscription();

        return response()->json([
            'data' => [
                'plan' => [
                    'key' => $user->plan()->key,
                    'name' => $user->plan()->name,
                ],
                'subscription' => $subscription === null
                    ? null
                    : (new SubscriptionResource($subscription->load(['plan', 'invoices'])))->toArray($request),
                'quota' => PlanQuota::meta($user),
            ],
        ]);
    }

    /**
     * Start a paid plan. Creates the subscription (not yet entitled), its
     * first invoice, and the Snap token the browser opens.
     */
    public function checkout(Request $request, MidtransService $midtrans): JsonResponse
    {
        $validated = $request->validate([
            'plan' => ['required', 'string', 'in:premium,team'],
            'period' => ['required', 'string', 'in:monthly,yearly'],
            'seats' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $user = $request->user();
        $plan = Plan::query()
            ->where('key', $validated['plan'])
            ->where('is_active', true)
            ->firstOrFail();

        // Already on this exact plan and still entitled? Nothing to buy —
        // changing seats or period is a Fase 5b concern, not a second sale.
        $current = $user->activeSubscription();
        if ($current !== null && $current->plan->key === $plan->key) {
            throw ValidationException::withMessages([
                'plan' => 'You’re already on '.$plan->name.'.',
            ]);
        }

        $seats = (int) ($validated['seats'] ?? 1);
        $amount = $plan->priceFor($validated['period'], $seats);

        $invoice = DB::transaction(function () use ($user, $plan, $validated, $seats, $amount) {
            $subscription = $user->subscriptions()->create([
                'plan_id' => $plan->id,
                'billing_period' => $validated['period'],
                'seats' => $seats,
                // Not entitled until the webhook says the money arrived.
                'status' => SubscriptionStatus::Expired,
            ]);

            return $subscription->invoices()->create([
                'number' => Invoice::nextNumber(),
                'amount' => $amount,
                'status' => InvoiceStatus::Pending,
                'midtrans_order_id' => 'CS-'.$subscription->ulid,
                'due_at' => now()->addHours(Invoice::DUE_HOURS),
            ]);
        });

        $token = $midtrans->snapToken($invoice->load('subscription.plan'), $user);
        $invoice->forceFill(['midtrans_snap_token' => $token])->save();

        return response()->json([
            'data' => [
                'snap_token' => $token,
                'order_id' => $invoice->midtrans_order_id,
                'amount' => $invoice->amount,
                'invoice' => (new InvoiceResource($invoice))->toArray($request),
            ],
        ], 201);
    }

    /**
     * Cancel — but the plan runs to the end of the period already paid for.
     * "Cancel any time" is a promise about the next bill, not this one.
     */
    public function cancel(Request $request): JsonResponse
    {
        $subscription = $request->user()->activeSubscription();

        if ($subscription === null) {
            throw ValidationException::withMessages([
                'plan' => 'There’s no paid plan to cancel.',
            ]);
        }

        $subscription->forceFill(['cancelled_at' => now()])->save();

        return response()->json([
            'data' => (new SubscriptionResource($subscription->load('plan')))->toArray($request),
        ]);
    }

    /**
     * Billing history — Settings → Subscription, and the "Open Settings →
     * Billing" link the Orders tab has always pointed at.
     */
    public function invoices(Request $request): AnonymousResourceCollection
    {
        $invoices = Invoice::query()
            ->whereIn('subscription_id', $request->user()->subscriptions()->select('id'))
            ->with('subscription.plan')
            ->latest('id')
            ->limit(50)
            ->get();

        return InvoiceResource::collection($invoices);
    }

    /**
     * The public pricing table. /pricing renders exactly these rows — no
     * number on that page is written in the frontend.
     */
    public function plans(): AnonymousResourceCollection
    {
        return PlanResource::collection(
            Plan::query()->where('is_active', true)->orderBy('sort_order')->get(),
        );
    }
}
