<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Midtrans, by hand. Deliberately not the midtrans/midtrans-php SDK: we
 * call one endpoint and verify one signature, and the SDK's global static
 * config would fight the way this app reads config.
 *
 * Sandbox and production differ only in host — the server key decides which
 * one the credentials belong to.
 */
class MidtransService
{
    private const SNAP_SANDBOX = 'https://app.sandbox.midtrans.com/snap/v1';

    private const SNAP_PRODUCTION = 'https://app.midtrans.com/snap/v1';

    /**
     * A Snap token for this invoice: what the browser hands to Midtrans'
     * popup. Expiry mirrors the invoice's own due date, so a token can't
     * outlive the bill it pays.
     *
     * @throws ConnectionException
     */
    public function snapToken(Invoice $invoice, User $user): string
    {
        return $this->token(
            orderId: (string) $invoice->midtrans_order_id,
            amount: $invoice->amount,
            itemId: $invoice->number,
            itemName: $this->itemName($invoice),
            buyerName: $user->name,
            buyerEmail: $user->email,
            finishUrl: config('app.frontend_url').'/settings?section=subscription',
        );
    }

    /**
     * The one place a Snap transaction is created.
     *
     * @throws ConnectionException
     */
    private function token(
        string $orderId,
        int $amount,
        string $itemId,
        string $itemName,
        string $buyerName,
        string $buyerEmail,
        string $finishUrl,
        int $expiryHours = Invoice::DUE_HOURS,
    ): string {
        $serverKey = (string) config('services.midtrans.server_key');

        if ($serverKey === '') {
            throw new RuntimeException('Midtrans server key is not configured.');
        }

        $response = Http::withBasicAuth($serverKey, '')
            ->acceptJson()
            ->post($this->snapUrl().'/transactions', [
                'transaction_details' => [
                    'order_id' => $orderId,
                    'gross_amount' => $amount,
                ],
                'customer_details' => [
                    'first_name' => $buyerName,
                    'email' => $buyerEmail,
                ],
                'item_details' => [[
                    'id' => $itemId,
                    // Midtrans rejects an over-long item name outright.
                    'name' => mb_substr($itemName, 0, 50),
                    'price' => $amount,
                    'quantity' => 1,
                ]],
                'expiry' => [
                    'unit' => 'hour',
                    'duration' => $expiryHours,
                ],
                'callbacks' => ['finish' => $finishUrl],
            ]);

        $response->throw();

        $token = $response->json('token');

        if (! is_string($token) || $token === '') {
            throw new RuntimeException('Midtrans returned no Snap token.');
        }

        return $token;
    }

    /**
     * Midtrans signs every notification with
     * sha512(order_id + status_code + gross_amount + server_key).
     *
     * This is the webhook's only authentication — the endpoint is public,
     * so a bad signature must be indistinguishable from a stranger.
     *
     * @param  array<string, mixed>  $payload
     */
    public function verifySignature(array $payload): bool
    {
        $signature = (string) ($payload['signature_key'] ?? '');

        if ($signature === '') {
            return false;
        }

        $expected = hash('sha512',
            (string) ($payload['order_id'] ?? '')
            .(string) ($payload['status_code'] ?? '')
            // gross_amount arrives as "94000.00" — signed as the string
            // Midtrans sent, never re-formatted.
            .(string) ($payload['gross_amount'] ?? '')
            .(string) config('services.midtrans.server_key'),
        );

        return hash_equals($expected, $signature);
    }

    /**
     * The four outcomes we act on. `capture` needs the fraud verdict too:
     * a captured card that failed screening is not money in the bank.
     *
     * @param  array<string, mixed>  $payload
     * @return 'paid'|'pending'|'failed'|'expired'
     */
    public function outcome(array $payload): string
    {
        $status = (string) ($payload['transaction_status'] ?? '');
        $fraud = (string) ($payload['fraud_status'] ?? 'accept');

        return match ($status) {
            'capture' => $fraud === 'accept' ? 'paid' : 'pending',
            'settlement' => 'paid',
            'pending' => 'pending',
            'deny', 'cancel' => 'failed',
            'expire' => 'expired',
            default => 'pending',
        };
    }

    private function snapUrl(): string
    {
        return config('services.midtrans.is_production')
            ? self::SNAP_PRODUCTION
            : self::SNAP_SANDBOX;
    }

    private function itemName(Invoice $invoice): string
    {
        $subscription = $invoice->subscription;

        return $subscription->plan->name.' — '.$subscription->billing_period->value;
    }
}
