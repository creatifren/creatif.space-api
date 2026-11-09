<?php

namespace App\Services;

use App\Models\CreditPurchase;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Creem, by hand. Not the SDK: a handful of endpoints and one HMAC, read
 * from config per call like the rest of this app.
 *
 * Creem is the merchant of record — it takes the card, the tax and the
 * receipts. Checkout is a hosted page the browser is sent to; nothing here
 * marks anything paid, the webhook does.
 */
class CreemService
{
    private const TEST_HOST = 'https://test-api.creem.io/v1';

    private const LIVE_HOST = 'https://api.creem.io/v1';

    /**
     * A hosted checkout for a plan's first payment. `request_id` is our
     * invoice number, so the webhook can find the bill it settles.
     *
     * `$productId` is the plan's product, or its trial twin. A discount code
     * (Founding 100) is a bonus, never a condition: if Creem refuses it
     * (used up, expired, not for this product) the checkout is opened again
     * without it rather than failing the purchase.
     *
     * @return array{id: string, checkout_url: string}
     *
     * @throws ConnectionException|RequestException
     */
    public function subscriptionCheckout(Invoice $invoice, User $user, ?string $productId = null, ?string $discountCode = null): array
    {
        $subscription = $invoice->subscription;
        $body = [
            'product_id' => $this->product($productId ?? $subscription->plan->creemProduct($subscription->billing_period->value)),
            'request_id' => $invoice->number,
            'customer' => ['email' => $user->email],
            'metadata' => ['invoice' => $invoice->ulid, 'subscription' => $subscription->ulid],
            'success_url' => config('app.frontend_url').'/settings?section=subscription&checkout=done',
        ];

        if ($discountCode === null) {
            return $this->checkout($body);
        }

        try {
            return $this->checkout($body + ['discount_code' => $discountCode]);
        } catch (RequestException $e) {
            // A 4xx is Creem's verdict on the request — most likely the code.
            // A 5xx is not about the code; let it fail as it would have.
            if (! $e->response->clientError()) {
                throw $e;
            }
            Log::warning('billing.discount_code_rejected', [
                'invoice' => $invoice->number,
                'code' => $discountCode,
                'status' => $e->response->status(),
                'error' => mb_substr((string) $e->response->body(), 0, 300),
            ]);

            return $this->checkout($body);
        }
    }

    /**
     * A hosted checkout for Credits: the $1 product, bought `units` times.
     *
     * @return array{id: string, checkout_url: string}
     *
     * @throws ConnectionException|RequestException
     */
    public function creditsCheckout(CreditPurchase $purchase, User $user): array
    {
        return $this->checkout([
            'product_id' => $this->product(config('services.creem.products.credits')),
            'units' => $purchase->units,
            'request_id' => $purchase->ulid,
            'customer' => ['email' => $user->email],
            'metadata' => ['credit_purchase' => $purchase->ulid],
            'success_url' => config('app.frontend_url').'/credits?checkout=done',
        ]);
    }

    /**
     * Move a running subscription to another plan or period, prorated and
     * charged now. The plan on our side changes when the webhook says so.
     *
     * @throws ConnectionException|RequestException
     */
    public function upgrade(Subscription $subscription, string $productId): void
    {
        $this->api()->post("/subscriptions/{$subscription->creem_subscription_id}/upgrade", [
            'product_id' => $this->product($productId),
            'update_behavior' => 'proration-charge-immediately',
        ]);
    }

    /**
     * Stop the next renewal. The plan keeps running to the end of the period
     * already paid for — "cancel any time" is a promise about the next bill.
     *
     * @throws ConnectionException|RequestException
     */
    public function cancel(Subscription $subscription): void
    {
        $this->api()->post("/subscriptions/{$subscription->creem_subscription_id}/cancel", [
            'mode' => 'scheduled',
            'onExecute' => 'cancel',
        ]);
    }

    /**
     * Creem's customer portal: change the card, read receipts, cancel.
     *
     * @throws ConnectionException|RequestException
     */
    public function portalLink(Subscription $subscription): string
    {
        $link = $this->api()->post('/customers/billing', [
            'customer_id' => $subscription->creem_customer_id,
        ])->json('customer_portal_link');

        if (! is_string($link) || $link === '') {
            throw new RuntimeException('Creem returned no portal link.');
        }

        return $link;
    }

    /**
     * Fetch a subscription or transaction to reconcile a missed or sparse
     * event. GET uses query parameters, not an id appended to the route.
     *
     * @return array<string, mixed>
     */
    public function subscription(string $id): array
    {
        return $this->api()->get('/subscriptions', ['subscription_id' => $id])->json();
    }

    /** @return array<string, mixed> */
    public function fetchProduct(string $id): array
    {
        return $this->api()->get('/products/'.rawurlencode($id))->json();
    }

    /** @return array<string, mixed> */
    public function transaction(string $id): array
    {
        return $this->api()->get('/transactions', ['transaction_id' => $id])->json();
    }

    /**
     * Creem signs the raw body with HMAC-SHA256 under the webhook secret and
     * sends the hex digest in `creem-signature`.
     *
     * This is the webhook's only authentication — the endpoint is public, so
     * a bad signature must be indistinguishable from a stranger. Computed
     * over the bytes as received, never a re-encoded array.
     */
    public function verifySignature(string $rawBody, string $signature): bool
    {
        $secret = (string) config('services.creem.webhook_secret');

        return $secret !== ''
            && $signature !== ''
            && hash_equals(hash_hmac('sha256', $rawBody, $secret), $signature);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array{id: string, checkout_url: string}
     *
     * @throws ConnectionException|RequestException
     */
    private function checkout(array $body): array
    {
        $response = $this->api()->post('/checkouts', $body);
        $id = $response->json('id');
        $url = $response->json('checkout_url');

        if (! is_string($id) || ! is_string($url) || $url === '') {
            throw new RuntimeException('Creem returned no checkout URL.');
        }

        return ['id' => $id, 'checkout_url' => $url];
    }

    private function product(?string $id): string
    {
        if ($id === null || $id === '') {
            throw new RuntimeException('That Creem product is not configured.');
        }

        return $id;
    }

    private function api(): PendingRequest
    {
        $key = (string) config('services.creem.api_key');

        if ($key === '') {
            throw new RuntimeException('Creem API key is not configured.');
        }

        return Http::baseUrl(config('services.creem.test_mode') ? self::TEST_HOST : self::LIVE_HOST)
            ->withHeaders(['x-api-key' => $key])
            ->acceptJson()
            ->asJson()
            ->connectTimeout(5)
            ->timeout(20)
            ->throw();
    }
}
