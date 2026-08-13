<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Offer;
use App\Models\Order;
use App\Services\MidtransService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Buying. The client is signed in on the `client` guard — the same identity
 * that approves files — so a purchase has a name on it.
 */
class OrderController extends Controller
{
    /**
     * Start a purchase: snapshot the fee, create the order, hand back a
     * Snap token. Nothing is credited until the webhook says it was paid.
     */
    public function store(Request $request, MidtransService $midtrans): JsonResponse
    {
        $validated = $request->validate([
            'offer_id' => ['required', 'string', 'max:26'],
            // Only meaningful for a tip, where the buyer names the amount.
            'amount' => ['sometimes', 'integer', 'min:1000', 'max:1000000000'],
        ]);

        $client = $request->user('client');

        $offer = Offer::query()
            ->where('ulid', $validated['offer_id'])
            ->where('is_active', true)
            ->with('user', 'space')
            ->first();

        if ($offer === null) {
            throw ValidationException::withMessages([
                'offer_id' => 'That isn’t for sale.',
            ]);
        }

        if (! $offer->isBuyable()) {
            throw ValidationException::withMessages([
                'offer_id' => 'This one is arranged in conversation, not bought here.',
            ]);
        }

        $amount = $this->amountFor($offer, $validated);

        // The fee is read once, here, and written into the order. A creator
        // who upgrades next month doesn't change what this sale cost them.
        $feePercent = $offer->user->plan()->fee_percent;
        $split = Order::split($amount, $feePercent);

        $order = DB::transaction(fn () => Order::query()->create([
            'creator_id' => $offer->user_id,
            'client_id' => $client->id,
            'offer_id' => $offer->id,
            'space_id' => $offer->space_id,
            'amount' => $amount,
            'fee_percent' => $feePercent,
            'fee_amount' => $split['fee'],
            'net_amount' => $split['net'],
            'status' => OrderStatus::Pending,
            'midtrans_order_id' => 'OR-'.(string) str()->ulid(),
        ]));

        $token = $midtrans->snapTokenForOrder(
            $order,
            config('app.frontend_url').$this->finishPath($offer),
        );

        return response()->json([
            'data' => [
                'snap_token' => $token,
                'order_id' => $order->midtrans_order_id,
                'amount' => $order->amount,
                'order' => (new OrderResource($order->load(['offer', 'creator'])))->toArray($request),
            ],
        ], 201);
    }

    /**
     * What this client has bought — Insights → Orders → Purchase.
     */
    public function mine(Request $request): AnonymousResourceCollection
    {
        $orders = $request->user('client')->orders()
            ->with(['offer', 'creator', 'space'])
            ->latest('id')
            ->limit(50)
            ->get();

        return OrderResource::collection($orders);
    }

    /**
     * A tip takes whatever the buyer offered; everything else costs what it
     * says on the label — the client never names that price.
     *
     * @param  array<string, mixed>  $validated
     */
    private function amountFor(Offer $offer, array $validated): int
    {
        if (! $offer->needsBuyerAmount()) {
            return (int) $offer->price;
        }

        $amount = $validated['amount'] ?? null;

        if ($amount === null) {
            throw ValidationException::withMessages([
                'amount' => 'Choose how much you’d like to send.',
            ]);
        }

        return (int) $amount;
    }

    /**
     * Back to where they were buying from.
     */
    private function finishPath(Offer $offer): string
    {
        $handle = $offer->user->handle?->name;

        if ($handle === null) {
            return '/';
        }

        return $offer->space !== null
            ? "/{$handle}/{$offer->space->slug}"
            : "/{$handle}";
    }
}
