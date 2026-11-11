<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\GooglePlayRefused;
use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Models\User;
use App\Services\GooglePlayClient;
use App\Support\GooglePlayBilling;
use App\Support\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * POST /billing/google/verify — the Android app hands over a Play purchase
 * after the Billing Library reports it. Google, not the app, says what was
 * bought: the token is read back from purchases.subscriptionsv2 and must
 * carry this account's ULID as obfuscatedExternalAccountId.
 *
 * One plan at a time: a running web (Creem) plan refuses the Play one, and
 * the purchase is then left unacknowledged, so Google refunds it.
 */
class GooglePlayBillingController extends Controller
{
    public function verify(Request $request, GooglePlayClient $client, GooglePlayBilling $billing): JsonResponse
    {
        $user = $request->user();
        Workspace::ownerOnly($user);
        $validated = $request->validate([
            'purchase_token' => ['required', 'string', 'max:512'],
            'product_id' => ['required', 'string', 'max:128'],
        ]);
        $token = $validated['purchase_token'];

        if (GooglePlayBilling::product($validated['product_id']) === null) {
            throw GooglePlayRefused::unknownProduct();
        }

        $remote = $client->getSubscriptionV2($token);
        $lineItem = GooglePlayBilling::lineItem($remote, $validated['product_id']) ?? throw GooglePlayRefused::unknownProduct();
        if (GooglePlayBilling::accountId($remote) !== $user->ulid) {
            throw GooglePlayRefused::accountMismatch();
        }

        $state = GooglePlayBilling::state($remote);
        if ($state === GooglePlayBilling::PENDING) {
            return response()->json([
                'message' => 'The payment is still pending on Google Play. Your plan starts once it clears.',
                'reason' => 'pending',
            ], 202);
        }

        $row = DB::transaction(function () use ($user, $token, $remote, $lineItem, $state) {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $row = Subscription::query()
                ->where('google_purchase_token_hash', GooglePlayBilling::hash($token))
                ->lockForUpdate()
                ->first();
            if ($row !== null && $row->user_id !== $user->id) {
                throw GooglePlayRefused::accountMismatch();
            }

            $target = GooglePlayBilling::target($state, $lineItem, $row) ?? throw GooglePlayRefused::invalidToken();

            if (Subscription::grants($target['status'], $target['period_end'])
                && $user->subscriptions()->where('provider', Subscription::PROVIDER_CREEM)->entitled()->exists()) {
                throw GooglePlayRefused::activeWebSubscription();
            }

            return GooglePlayBilling::apply($user, $row, $token, $remote, $lineItem, $target);
        });

        $billing->acknowledgeIfPending($row, $remote);

        return response()->json([
            'plan' => $row->plan->key,
            'period' => $row->billing_period->value,
            'status' => $row->status->value,
            'current_period_end' => $row->current_period_end?->toIso8601String(),
            'provider' => $row->provider,
        ]);
    }
}
