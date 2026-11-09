<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\InvoiceStatus;
use App\Enums\PlanKey;
use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\InvoiceResource;
use App\Http\Resources\PlanResource;
use App\Http\Resources\SubscriptionResource;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\User;
use App\Services\CreemService;
use App\Support\Credits;
use App\Support\PlanQuota;
use App\Support\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Settings → Subscription. Checkout is Creem's hosted page; nothing here
 * ever marks an invoice paid or a plan active — only the webhook does that.
 * Creem renews by itself, so there is no "pay the next bill" path either.
 */
class SubscriptionController extends Controller
{
    /**
     * The current plan, its period, and the last bill.
     */
    public function show(Request $request): JsonResponse
    {
        Workspace::ownerOnly($request->user());
        $user = $request->user();
        $subscription = $user->activeSubscription();

        return response()->json([
            'data' => [
                'plan' => [
                    'key' => $user->plan()->key,
                    'name' => $user->plan()->name,
                    // The flags the server gates on, so a screen locks the
                    // same features the API refuses (RF-049: custom_fonts).
                    'features' => (object) ($user->plan()->features ?? []),
                ],
                'subscription' => $subscription === null
                    ? null
                    : (new SubscriptionResource($subscription->load(['plan', 'invoices'])))->toArray($request),
                'quota' => PlanQuota::meta($user),
                // Legacy: the old count-based allowance, no longer charged.
                'allowance' => [
                    'limit' => PlanQuota::socialPostsLimit($user),
                    'used' => Credits::allowanceUsed($user),
                ],
                'monthly_balance' => Credits::monthlyBalance($user),
                'monthly_resets_at' => Credits::cycleEnds($user)->toIso8601String(),
                'monthly_amount' => Credits::monthlyAmount($user),
                'topup_balance' => Credits::balance($user),
                'total' => Credits::total($user),
                'seats' => Workspace::seats($user),
                'seats_used' => Workspace::seatsUsed($user),
            ],
        ]);
    }

    /**
     * Start Pro or Max. Creates the subscription (not yet entitled), its
     * first invoice, and the Creem checkout the browser is sent to.
     *
     * Already paying? Then this is a change of plan or period: Creem
     * prorates and charges the difference now, and the plan on our side
     * moves when its webhook arrives.
     */
    public function checkout(Request $request, CreemService $creem): JsonResponse
    {
        Workspace::ownerOnly($request->user());
        $validated = $request->validate([
            'plan' => ['required', 'string', Rule::in(PlanKey::sold())],
            'period' => ['required', 'string', 'in:monthly,yearly'],
        ]);

        $user = $request->user();
        $plan = Plan::query()
            ->where('key', $validated['plan'])
            ->where('is_active', true)
            ->firstOrFail();

        $current = $user->activeSubscription();

        if ($current !== null) {
            if ($current->plan->key === $plan->key && $current->billing_period->value === $validated['period']) {
                throw ValidationException::withMessages([
                    'plan' => 'You’re already on '.$plan->name.'.',
                ]);
            }

            if ($current->creem_subscription_id === null) {
                throw ValidationException::withMessages([
                    'plan' => 'This plan can’t be changed online yet — it ends on its own; choose again after.',
                ]);
            }

            $creem->upgrade($current, (string) $plan->creemProduct($validated['period']));

            return response()->json(['data' => [
                'checkout_url' => null,
                'changing_to' => ['plan' => $plan->key, 'period' => $validated['period']],
            ]], 202);
        }

        $invoice = DB::transaction(function () use ($user, $plan, $validated) {
            // A double click or a second tab must not open a second paid
            // subscription: the same unpaid choice reuses its checkout.
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            $open = Invoice::query()
                ->whereIn('subscription_id', $user->subscriptions()->where('status', SubscriptionStatus::Expired)->select('id'))
                ->where('status', InvoiceStatus::Pending)
                ->where('due_at', '>', now())
                ->whereNotNull('creem_checkout_url')
                ->latest('id')
                ->with('subscription.plan')
                ->first();

            if ($open !== null
                && $open->subscription->plan_id === $plan->id
                && $open->subscription->billing_period->value === $validated['period']) {
                return $open;
            }

            // Another choice replaces the earlier unpaid one rather than
            // standing beside it, so only one checkout can ever activate.
            if ($open !== null) {
                $open->forceFill(['status' => InvoiceStatus::Expired])->save();
            }

            $subscription = $user->subscriptions()->create([
                'plan_id' => $plan->id,
                'billing_period' => $validated['period'],
                'seats' => 1,
                // Not entitled until the webhook says the money arrived.
                'status' => SubscriptionStatus::Expired,
            ]);

            return $subscription->invoices()->create([
                'number' => Invoice::nextNumber(),
                'amount' => $plan->priceFor($validated['period']),
                'currency' => 'USD',
                'status' => InvoiceStatus::Pending,
                'due_at' => now()->addHours(Invoice::DUE_HOURS),
            ]);
        });

        // Reused bill: the same hosted page, never a second checkout for it.
        if ($invoice->creem_checkout_url === null) {
            $checkout = $creem->subscriptionCheckout($invoice->load('subscription.plan'), $user);
            $invoice->forceFill([
                'creem_checkout_id' => $checkout['id'],
                'creem_checkout_url' => $checkout['checkout_url'],
            ])->save();
        }

        return response()->json([
            'data' => [
                'checkout_url' => $invoice->creem_checkout_url,
                'amount' => $invoice->amount,
                'invoice' => (new InvoiceResource($invoice))->toArray($request),
            ],
        ], 201);
    }

    /**
     * Seats belong to Team, which is not sold yet.
     */
    public function seats(Request $request): JsonResponse
    {
        Workspace::ownerOnly($request->user());

        throw ValidationException::withMessages([
            'seats' => 'Seats are part of the Team plan.',
        ]);
    }

    /**
     * Cancel — but the plan runs to the end of the period already paid for.
     * "Cancel any time" is a promise about the next bill, not this one.
     * Creem stops the renewal; its webhook records the cancellation.
     */
    public function cancel(Request $request, CreemService $creem): JsonResponse
    {
        Workspace::ownerOnly($request->user());
        $subscription = $request->user()->activeSubscription();

        if ($subscription === null) {
            throw ValidationException::withMessages([
                'plan' => 'There’s no paid plan to cancel.',
            ]);
        }

        if ($subscription->creem_subscription_id !== null) {
            $creem->cancel($subscription);
        }

        // Recorded now as well, so the screen says so before the webhook
        // lands; the webhook writing the same thing again changes nothing.
        $subscription->forceFill(['cancelled_at' => $subscription->cancelled_at ?? now()])->save();

        return response()->json([
            'data' => (new SubscriptionResource($subscription->load('plan')))->toArray($request),
        ]);
    }

    /**
     * Creem's customer portal: card, receipts, cancelling.
     */
    public function portal(Request $request, CreemService $creem): JsonResponse
    {
        Workspace::ownerOnly($request->user());
        $subscription = $request->user()->activeSubscription();

        if ($subscription === null || $subscription->creem_customer_id === null) {
            throw ValidationException::withMessages([
                'plan' => 'There’s no paid plan to manage.',
            ]);
        }

        return response()->json(['data' => ['url' => $creem->portalLink($subscription)]]);
    }

    /**
     * Billing history — Settings → Subscription.
     */
    public function invoices(Request $request): AnonymousResourceCollection
    {
        Workspace::ownerOnly($request->user());
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
