<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\WalletTransactionType;
use App\Exceptions\GooglePlayRefused;
use App\Exceptions\GooglePlayUnavailable;
use App\Http\Controllers\Controller;
use App\Models\CreditPurchase;
use App\Models\User;
use App\Services\GooglePlayClient;
use App\Support\Credits;
use App\Support\ProductEvents;
use App\Support\Wallet;
use App\Support\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * POST /billing/google/credits (P10) — the Android app hands over a Play
 * Credits top-up (one-time, consumable). Google, not the app, says what was
 * bought: purchases.products.get must say purchased and carry this account's
 * ULID as obfuscatedExternalAccountId. The Credits land once per purchase
 * token (the receipt is a credit_purchases row keyed by the token's hash),
 * then the purchase is consumed so it can be bought again. A retry after a
 * failed consume finds the receipt, grants nothing and consumes again.
 */
class GooglePlayCreditsController extends Controller
{
    /** Play product id → [Credits, list price in US cents]. */
    public const PRODUCTS = [
        'credits_50000' => [50_000, 499],
        'credits_100000' => [100_000, 999],
        'credits_250000' => [250_000, 2499],
    ];

    public function store(Request $request, GooglePlayClient $client): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        Workspace::ownerOnly($user);
        $validated = $request->validate([
            'purchase_token' => ['required', 'string', 'max:512'],
            'product_id' => ['required', 'string', 'max:128'],
        ]);
        $token = $validated['purchase_token'];
        $productId = $validated['product_id'];
        [$credits, $cents] = self::PRODUCTS[$productId] ?? throw GooglePlayRefused::unknownCredits();

        $remote = $client->getProduct($productId, $token);
        if (($remote['obfuscatedExternalAccountId'] ?? null) !== $user->ulid) {
            throw GooglePlayRefused::accountMismatch();
        }
        $state = (int) ($remote['purchaseState'] ?? -1);
        if ($state === 2) {
            return response()->json([
                'message' => 'The payment is still pending on Google Play. Your Credits land once it clears.',
                'reason' => 'pending',
            ], 202);
        }
        if ($state !== 0) {
            throw GooglePlayRefused::invalidToken();
        }
        $quantity = max(1, (int) ($remote['quantity'] ?? 1));
        $handle = self::handle($token);

        $added = DB::transaction(function () use ($user, $handle, $credits, $cents, $quantity, $productId, $remote) {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $receipt = CreditPurchase::query()->where('creem_checkout_id', $handle)->lockForUpdate()->first();
            if ($receipt !== null) {
                if ($receipt->user_id !== $user->id) {
                    throw GooglePlayRefused::accountMismatch();
                }

                return 0;
            }
            $total = $credits * $quantity;
            $purchase = CreditPurchase::query()->create([
                'user_id' => $user->id,
                'credits' => $total,
                'units' => $quantity,
                'amount' => $cents * $quantity,
                'currency' => 'USD',
                'status' => 'paid',
                'creem_checkout_id' => $handle,
                'payment_method' => 'google_play',
                'paid_at' => now(),
                'raw_notification' => ['product_id' => $productId, 'order_id' => $remote['orderId'] ?? null, 'quantity' => $quantity],
            ]);
            Wallet::credit($user, WalletTransactionType::CreditsTopUp, $total,
                'credit_purchase', $purchase->id, 'Google Play '.($remote['orderId'] ?? $productId), Wallet::CREDITS);
            ProductEvents::creditsPurchased($user, $cents * $quantity, $total);

            return $total;
        });

        if ((int) ($remote['consumptionState'] ?? 0) !== 1) {
            try {
                $client->consume($productId, $token);
            } catch (GooglePlayUnavailable $e) {
                // The Credits are in; the app sends it again on its next start and we consume then.
                Log::warning('google play consume failed', ['product' => $productId, 'error' => $e->getMessage()]);
            }
        }

        return response()->json([
            'credits_added' => $added,
            'already_added' => $added === 0,
            'balance' => Credits::total($user),
        ]);
    }

    /** The receipt's idempotency handle (fits credit_purchases.creem_checkout_id, 64). */
    public static function handle(string $token): string
    {
        return 'gp_'.substr(hash('sha256', $token), 0, 61);
    }
}
