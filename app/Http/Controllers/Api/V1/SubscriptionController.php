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
use App\Models\Subscription;
use App\Services\MidtransService;
use App\Support\PlanQuota;
use App\Support\Workspace;
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
        Workspace::ownerOnly($request->user());
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
                'seats' => Workspace::seats($user),
                'seats_used' => Workspace::seatsUsed($user),
                'seat_price_monthly' => $user->plan()->seat_price_monthly,
            ],
        ]);
    }

    /**
     * Start a paid plan. Creates the subscription (not yet entitled), its
     * first invoice, and the Snap token the browser opens.
     */
    public function checkout(Request $request, MidtransService $midtrans): JsonResponse
    {
        Workspace::ownerOnly($request->user());
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

        // Seats only mean anything on Team, and you cannot buy fewer than
        // the people you already have.
        if ($seats > 1 && $plan->key !== PlanKey::Team) {
            throw ValidationException::withMessages([
                'seats' => 'Extra people are part of '.Plan::query()->where('key', 'team')->value('name').'.',
            ]);
        }

        $inUse = Workspace::seatsUsed($user);
        if ($seats < $inUse) {
            throw ValidationException::withMessages([
                'seats' => 'You already have '.$inUse.' people on your team.',
            ]);
        }

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
     * Buy or release seats mid-plan.
     *
     * Going up is billed prorated for the days left and takes effect only
     * when the webhook says it was paid — the seat is sold, not lent.
     * Going down waits for the renewal: no refunds, because refunding a
     * part-month is a refund engine, and that is not what a seat toggle
     * should quietly become.
     *
     * ponytail: proration is linear on days and ignores the yearly-versus-
     * monthly nuance. Revisit when the first Team customer disagrees.
     */
    public function seats(Request $request, MidtransService $midtrans): JsonResponse
    {
        Workspace::ownerOnly($request->user());

        $validated = $request->validate([
            'seats' => ['required', 'integer', 'min:1', 'max:100'],
        ]);

        $user = $request->user();
        $subscription = $user->activeSubscription();

        if ($subscription === null || $subscription->plan->key !== PlanKey::Team) {
            throw ValidationException::withMessages([
                'seats' => 'Seats are part of the Team plan.',
            ]);
        }

        $wanted = (int) $validated['seats'];
        $inUse = Workspace::seatsUsed($user);

        if ($wanted < $inUse) {
            throw ValidationException::withMessages([
                'seats' => 'Remove somebody first — you have '.$inUse.' people on your team.',
            ]);
        }

        if ($wanted === $subscription->seats) {
            throw ValidationException::withMessages([
                'seats' => 'That’s how many you already have.',
            ]);
        }

        if ($wanted < $subscription->seats) {
            // Down is free and takes effect at renewal; the seats stay
            // usable until then because they are already paid for.
            $subscription->forceFill(['seats_at_renewal' => $wanted])->save();

            return response()->json([
                'data' => [
                    'seats' => $subscription->seats,
                    'seats_at_renewal' => $wanted,
                    'invoice' => null,
                ],
            ]);
        }

        $amount = $this->prorated($subscription, $wanted - $subscription->seats, $user->plan()->seat_price_monthly);

        $invoice = $subscription->invoices()->create([
            'number' => Invoice::nextNumber(),
            'amount' => $amount,
            'status' => InvoiceStatus::Pending,
            'midtrans_order_id' => 'CS-'.$subscription->ulid.'-S'.$wanted,
            'due_at' => now()->addHours(Invoice::DUE_HOURS),
        ]);

        // Recorded now, applied by the webhook: seats a person has not paid
        // for yet must not let them invite anybody.
        $subscription->forceFill(['seats_pending' => $wanted])->save();

        $token = $midtrans->snapToken($invoice->load('subscription.plan'), $user);
        $invoice->forceFill(['midtrans_snap_token' => $token])->save();

        return response()->json([
            'data' => [
                'snap_token' => $token,
                'seats' => $subscription->seats,
                'seats_pending' => $wanted,
                'amount' => $amount,
                'invoice' => (new InvoiceResource($invoice))->toArray($request),
            ],
        ], 201);
    }

    /**
     * What the extra seats cost for the days left in the period, rounded
     * up — a part-day is charged as a day rather than given away.
     */
    private function prorated(Subscription $subscription, int $extraSeats, int $seatPrice): int
    {
        $end = $subscription->current_period_end;
        $start = $subscription->current_period_start;

        if ($end === null || $start === null || $end->isPast()) {
            return $extraSeats * $seatPrice;
        }

        $total = max(1, (int) round($start->diffInDays($end)));
        $left = max(0, (int) ceil($end->diffInDays(now(), absolute: true)));

        return (int) ceil($extraSeats * $seatPrice * min($left, $total) / $total);
    }

    /**
     * Cancel — but the plan runs to the end of the period already paid for.
     * "Cancel any time" is a promise about the next bill, not this one.
     */
    public function cancel(Request $request): JsonResponse
    {
        Workspace::ownerOnly($request->user());
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
