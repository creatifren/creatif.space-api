<?php

use App\Enums\InvoiceStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Affiliate;
use App\Models\Commission;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Referral;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\SubscriptionStarted;
use Illuminate\Support\Facades\Notification;

/**
 * A notification body signed the way Midtrans signs one.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function midtransPayload(string $orderId, array $overrides = []): array
{
    $payload = array_merge([
        'order_id' => $orderId,
        'status_code' => '200',
        'gross_amount' => '94000.00',
        'transaction_status' => 'settlement',
        'payment_type' => 'qris',
        'fraud_status' => 'accept',
    ], $overrides);

    $payload['signature_key'] = hash('sha512',
        $payload['order_id']
        .$payload['status_code']
        .$payload['gross_amount']
        .config('services.midtrans.server_key'),
    );

    return $payload;
}

/**
 * A user mid-checkout: subscription created, invoice pending, nothing paid.
 *
 * @return array{0: User, 1: Subscription, 2: Invoice}
 */
function pendingCheckout(string $planKey = 'premium'): array
{
    $user = User::factory()->create();
    $subscription = Subscription::factory()->for($user)->create([
        'plan_id' => Plan::query()->where('key', $planKey)->value('id'),
        'status' => SubscriptionStatus::Expired,
        'current_period_start' => null,
        'current_period_end' => null,
    ]);
    $invoice = Invoice::factory()->for($subscription)->create([
        'midtrans_order_id' => 'CS-'.$subscription->ulid,
        'amount' => 94_000,
    ]);

    return [$user, $subscription, $invoice];
}

describe('signature', function () {
    it('refuses a forged signature and changes nothing', function () {
        [, $subscription, $invoice] = pendingCheckout();

        $payload = midtransPayload($invoice->midtrans_order_id);
        $payload['signature_key'] = str_repeat('0', 128);

        $this->postJson('/api/v1/webhooks/midtrans', $payload)->assertForbidden();

        expect($invoice->refresh()->status)->toBe(InvoiceStatus::Pending)
            ->and($subscription->refresh()->status)->toBe(SubscriptionStatus::Expired);
    });

    it('refuses a body with no signature at all', function () {
        [, , $invoice] = pendingCheckout();

        $this->postJson('/api/v1/webhooks/midtrans', [
            'order_id' => $invoice->midtrans_order_id,
            'transaction_status' => 'settlement',
        ])->assertForbidden();
    });

    it('refuses a signature computed over a different amount', function () {
        [, , $invoice] = pendingCheckout();

        // Signed for 1.000, sent claiming 94.000.
        $payload = midtransPayload($invoice->midtrans_order_id, ['gross_amount' => '1000.00']);
        $payload['gross_amount'] = '94000.00';

        $this->postJson('/api/v1/webhooks/midtrans', $payload)->assertForbidden();
    });

    it('404s for an order we never issued', function () {
        $this->postJson('/api/v1/webhooks/midtrans', midtransPayload('CS-nothing'))
            ->assertNotFound();
    });
});

describe('settlement', function () {
    it('marks the invoice paid and starts the plan', function () {
        Notification::fake();
        [$user, $subscription, $invoice] = pendingCheckout();

        $this->postJson('/api/v1/webhooks/midtrans', midtransPayload($invoice->midtrans_order_id))
            ->assertOk();

        $invoice->refresh();
        $subscription->refresh();

        expect($invoice->status)->toBe(InvoiceStatus::Paid)
            ->and($invoice->paid_at)->not->toBeNull()
            ->and($invoice->payment_method)->toBe('qris')
            // The whole body is kept: when money is disputed, the audit
            // trail matters more than the disk.
            ->and($invoice->raw_notification['transaction_status'])->toBe('settlement')
            ->and($subscription->status)->toBe(SubscriptionStatus::Active)
            ->and($subscription->current_period_end)->not->toBeNull()
            ->and($user->fresh()->plan()->key->value)->toBe('premium');

        Notification::assertSentTo($user, SubscriptionStarted::class);
    });

    it('gives a monthly plan one month and a yearly plan one year', function () {
        Notification::fake();
        [, $subscription, $invoice] = pendingCheckout();
        $subscription->forceFill(['billing_period' => 'yearly'])->save();

        $this->postJson('/api/v1/webhooks/midtrans', midtransPayload($invoice->midtrans_order_id));

        expect(now()->diffInDays($subscription->refresh()->current_period_end))
            ->toBeGreaterThan(360);
    });

    it('renews from the old period end, so paying late loses no days', function () {
        Notification::fake();
        [, $subscription, $invoice] = pendingCheckout();
        $endsAt = now()->addDays(10);
        $subscription->forceFill([
            'status' => SubscriptionStatus::PastDue,
            'current_period_end' => $endsAt,
        ])->save();

        $this->postJson('/api/v1/webhooks/midtrans', midtransPayload($invoice->midtrans_order_id));

        // Not one month from today — one month from where it ran out.
        expect($subscription->refresh()->current_period_end->toDateString())
            ->toBe($endsAt->copy()->addMonth()->toDateString());
    });

    it('treats a captured card as paid only when fraud screening accepts', function () {
        Notification::fake();
        [, $subscription, $invoice] = pendingCheckout();

        $this->postJson('/api/v1/webhooks/midtrans', midtransPayload(
            $invoice->midtrans_order_id,
            ['transaction_status' => 'capture', 'fraud_status' => 'challenge'],
        ))->assertOk();

        expect($invoice->refresh()->status)->toBe(InvoiceStatus::Pending)
            ->and($subscription->refresh()->status)->toBe(SubscriptionStatus::Expired);
    });
});

describe('idempotency', function () {
    it('absorbs a repeated settlement without moving anything twice', function () {
        Notification::fake();
        [$user, $subscription, $invoice] = pendingCheckout();
        $payload = midtransPayload($invoice->midtrans_order_id);

        $this->postJson('/api/v1/webhooks/midtrans', $payload)->assertOk();
        $firstEnd = $subscription->refresh()->current_period_end;
        $firstPaidAt = $invoice->refresh()->paid_at;

        // Midtrans retries. The period must not extend a second time.
        $this->postJson('/api/v1/webhooks/midtrans', $payload)->assertOk();

        expect($subscription->refresh()->current_period_end->eq($firstEnd))->toBeTrue()
            ->and($invoice->refresh()->paid_at->eq($firstPaidAt))->toBeTrue();

        Notification::assertSentToTimes($user, SubscriptionStarted::class, 1);
    });
});

describe('failure', function () {
    it('records an expiry without ever entitling the plan', function () {
        Notification::fake();
        [$user, $subscription, $invoice] = pendingCheckout();

        $this->postJson('/api/v1/webhooks/midtrans', midtransPayload(
            $invoice->midtrans_order_id,
            ['transaction_status' => 'expire'],
        ))->assertOk();

        expect($invoice->refresh()->status)->toBe(InvoiceStatus::Expired)
            ->and($subscription->refresh()->status)->toBe(SubscriptionStatus::Expired)
            ->and($user->fresh()->plan()->key->value)->toBe('free');

        Notification::assertNothingSent();
    });

    it('records a denial', function () {
        Notification::fake();
        [, , $invoice] = pendingCheckout();

        $this->postJson('/api/v1/webhooks/midtrans', midtransPayload(
            $invoice->midtrans_order_id,
            ['transaction_status' => 'deny'],
        ))->assertOk();

        expect($invoice->refresh()->status)->toBe(InvoiceStatus::Failed);
    });
});

describe('affiliate commission', function () {
    it('earns twelve parts when the customer was referred', function () {
        Notification::fake();
        [$user, , $invoice] = pendingCheckout();

        $affiliate = Affiliate::factory()->approved()->create();
        Referral::factory()->for($affiliate)->create([
            'referred_user_id' => $user->id,
        ]);

        $this->postJson('/api/v1/webhooks/midtrans', midtransPayload($invoice->midtrans_order_id))
            ->assertOk();

        $rows = Commission::query()->get();
        expect($rows)->toHaveCount(12)
            // 20% of 94.000, whole rupiah, nothing lost in the split.
            ->and($rows->sum('amount'))->toBe(18_800)
            ->and($affiliate->fresh()->paid_referrals_count)->toBe(1);
    });

    it('creates no thirteenth row however many times Midtrans retries', function () {
        Notification::fake();
        [$user, , $invoice] = pendingCheckout();

        $affiliate = Affiliate::factory()->approved()->create();
        Referral::factory()->for($affiliate)->create([
            'referred_user_id' => $user->id,
        ]);

        $payload = midtransPayload($invoice->midtrans_order_id);
        $this->postJson('/api/v1/webhooks/midtrans', $payload)->assertOk();
        $this->postJson('/api/v1/webhooks/midtrans', $payload)->assertOk();

        // The settled invoice absorbs the repeat before the engine is even
        // reached; the unique index is the second line of defence.
        expect(Commission::query()->count())->toBe(12)
            ->and($affiliate->fresh()->paid_referrals_count)->toBe(1);
    });

    it('earns nothing on a payment nobody referred', function () {
        Notification::fake();
        [, , $invoice] = pendingCheckout();

        $this->postJson('/api/v1/webhooks/midtrans', midtransPayload($invoice->midtrans_order_id))
            ->assertOk();

        expect(Commission::query()->count())->toBe(0);
    });
});
