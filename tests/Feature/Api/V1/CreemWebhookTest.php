<?php

use App\Enums\CommissionStatus;
use App\Enums\InvoiceStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\WalletTransactionType;
use App\Models\Commission;
use App\Models\CreditPurchase;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Referral;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\BillingNeedsReview;
use App\Support\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

function sendCreem(array $event, ?string $signature = null)
{
    $body = json_encode($event, JSON_THROW_ON_ERROR);

    return test()->call('POST', '/api/v1/webhooks/creem', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_CREEM_SIGNATURE' => $signature ?? hash_hmac('sha256', $body, config('services.creem.webhook_secret')),
    ], $body);
}

function creemPaid(Subscription $subscription, string $transaction = 'tran_first'): array
{
    return [
        'id' => 'evt_'.$transaction,
        'eventType' => 'subscription.paid',
        'object' => [
            'id' => 'sub_test', 'product' => 'prod_pro_monthly', 'customer' => 'cust_test',
            'metadata' => ['subscription' => $subscription->ulid],
            'last_transaction_id' => $transaction,
            'last_transaction' => ['id' => $transaction, 'status' => 'paid', 'amount' => 999, 'currency' => 'USD'],
            'current_period_start_date' => '2026-10-31T00:00:00Z',
            'current_period_end_date' => '2026-11-30T00:00:00Z',
        ],
    ];
}

beforeEach(function () {
    Notification::fake();
    Http::preventStrayRequests();
});

it('rejects a forged signature before writing', function () {
    sendCreem(['id' => 'evt_forged', 'eventType' => 'checkout.completed', 'object' => ['id' => 'ch_no']], 'forged')
        ->assertForbidden();
    expect(DB::table('webhook_events')->count())->toBe(0);
});

it('credits a verified top-up exactly once across different event ids', function () {
    $user = User::factory()->create();
    $purchase = CreditPurchase::create([
        'user_id' => $user->id, 'units' => 10, 'credits' => 100000, 'amount' => 1000,
        'currency' => 'USD', 'status' => 'pending', 'creem_checkout_id' => 'ch_topup',
    ]);
    $event = ['id' => 'evt_topup', 'eventType' => 'checkout.completed', 'object' => [
        'id' => 'ch_topup', 'request_id' => $purchase->ulid, 'product' => 'prod_credits',
        'order' => ['id' => 'ord_topup', 'status' => 'paid', 'amount' => 1000, 'currency' => 'USD'],
    ]];
    sendCreem($event)->assertOk();
    sendCreem($event)->assertOk();
    $event['id'] = 'evt_topup_other';
    sendCreem($event)->assertOk();
    expect(Wallet::balance($user, Wallet::CREDITS))->toBe(100000)
        ->and($purchase->fresh()->status)->toBe('paid');
});

it('rolls back the event receipt on amount mismatch so a corrected retry works', function () {
    $user = User::factory()->create();
    $purchase = CreditPurchase::create(['user_id' => $user->id, 'units' => 10, 'credits' => 100000, 'amount' => 1000, 'status' => 'pending']);
    $event = ['id' => 'evt_mismatch', 'eventType' => 'checkout.completed', 'object' => [
        'id' => 'ch_topup', 'request_id' => $purchase->ulid, 'product' => 'prod_credits',
        'order' => ['status' => 'paid', 'amount' => 100, 'currency' => 'USD'],
    ]];
    sendCreem($event)->assertUnprocessable();
    expect(DB::table('webhook_events')->count())->toBe(0);
    $event['object']['order']['amount'] = 1000;
    sendCreem($event)->assertOk();
    expect(Wallet::balance($user, Wallet::CREDITS))->toBe(100000);
});

it('settles paid before checkout and never pays one transaction twice', function () {
    $sub = Subscription::factory()->create(['status' => 'expired', 'current_period_start' => null, 'current_period_end' => null]);
    $invoice = Invoice::factory()->for($sub)->create();
    $event = creemPaid($sub);
    sendCreem($event)->assertOk();
    $event['id'] = 'evt_second_delivery';
    sendCreem($event)->assertOk();
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and($sub->fresh()->creem_subscription_id)->toBe('sub_test')
        ->and($sub->fresh()->current_period_end->toDateString())->toBe('2026-11-30')
        ->and(Invoice::query()->count())->toBe(1);
});

it('creates a separate invoice for a renewal', function () {
    $sub = Subscription::factory()->create(['status' => 'expired', 'current_period_end' => null]);
    Invoice::factory()->for($sub)->create();
    sendCreem(creemPaid($sub))->assertOk();
    $renewal = creemPaid($sub, 'tran_renewal');
    $renewal['object']['current_period_start_date'] = '2026-11-30T00:00:00Z';
    $renewal['object']['current_period_end_date'] = '2026-12-31T00:00:00Z';
    sendCreem($renewal)->assertOk();
    expect($sub->invoices()->count())->toBe(2)
        ->and($sub->fresh()->current_period_end->toDateString())->toBe('2026-12-31');
});

it('refuses Credits bought with a discount', function () {
    $user = User::factory()->create();
    $purchase = CreditPurchase::create(['user_id' => $user->id, 'units' => 10, 'credits' => 100000, 'amount' => 1000, 'status' => 'pending']);
    sendCreem(['id' => 'evt_coupon', 'eventType' => 'checkout.completed', 'object' => [
        'id' => 'ch_coupon', 'request_id' => $purchase->ulid, 'product' => 'prod_credits',
        'order' => ['status' => 'paid', 'amount' => 1000, 'amount_paid' => 0, 'discount_amount' => 1000, 'currency' => 'USD'],
    ]])->assertUnprocessable();
    expect(Wallet::balance($user, Wallet::CREDITS))->toBe(0);
});

it('moves to a shorter period even though it ends earlier', function () {
    $max = Plan::query()->where('key', 'max')->sole();
    $sub = Subscription::factory()->create([
        'plan_id' => $max->id, 'billing_period' => 'yearly', 'creem_subscription_id' => 'sub_test',
        'current_period_start' => '2026-10-01', 'current_period_end' => '2027-10-01',
    ]);
    sendCreem(creemPaid($sub, 'tran_switch'))->assertOk();
    expect($sub->fresh()->plan->key->value)->toBe('pro')
        ->and($sub->fresh()->billing_period->value)->toBe('monthly')
        ->and($sub->fresh()->current_period_end->toDateString())->toBe('2026-11-30');
});

it('opens a grace window on past_due', function () {
    $sub = Subscription::factory()->create(['creem_subscription_id' => 'sub_test']);
    sendCreem(['id' => 'evt_due', 'eventType' => 'subscription.past_due', 'object' => ['id' => 'sub_test']])->assertOk();
    expect($sub->fresh()->status)->toBe(SubscriptionStatus::PastDue)
        ->and($sub->fresh()->grace_ends_at)->not->toBeNull();
});

it('a second paid plan is activated (never charged without access); the live first one is left for a manual refund', function () {
    // P0 14 Nov: a payment always buys access. The earlier live paid plan is
    // logged (billing.duplicate_subscription_paid) for a refund by hand.
    $live = Subscription::factory()->create(['creem_subscription_id' => 'sub_live']);
    $live->invoices()->create(['number' => Invoice::nextNumber(), 'amount' => 999, 'currency' => 'USD', 'status' => 'paid', 'creem_transaction_id' => 'tran_live', 'paid_at' => now()->subDay()]);
    $second = Subscription::factory()->for($live->user)->create(['status' => 'expired', 'current_period_start' => null, 'current_period_end' => null]);
    Invoice::factory()->for($second)->create();
    sendCreem(creemPaid($second, 'tran_dup'))->assertOk();
    expect($second->fresh()->status)->toBe(SubscriptionStatus::Active)
        ->and($live->user->fresh()->activeSubscription()->id)->toBe($second->id)
        ->and($live->fresh()->status)->toBe(SubscriptionStatus::Active);
});

it('does not activate on an unpaid product update', function () {
    $sub = Subscription::factory()->create(['creem_subscription_id' => 'sub_test']);
    $event = ['id' => 'evt_update', 'eventType' => 'subscription.update', 'object' => ['id' => 'sub_test', 'product' => 'prod_max_monthly']];
    sendCreem($event)->assertOk();
    expect($sub->fresh()->plan->key->value)->toBe('pro');
});

function creemRefund(array $transaction, array $checkout = [], string $id = 'ref_1'): array
{
    return ['id' => 'evt_'.$id, 'eventType' => 'refund.created', 'object' => [
        'id' => $id, 'status' => 'succeeded', 'refund_amount' => $transaction['refunded_amount'], 'refund_currency' => 'USD',
        'transaction' => $transaction + ['currency' => 'USD', 'status' => 'refunded'],
        'checkout' => $checkout,
    ]];
}

function paidTopUp(User $user): CreditPurchase
{
    $purchase = CreditPurchase::create(['user_id' => $user->id, 'units' => 10, 'credits' => 100000, 'amount' => 1000, 'status' => 'paid']);
    Wallet::credit($user, WalletTransactionType::CreditsTopUp, 100000, 'credit_purchase', $purchase->id, null, Wallet::CREDITS);

    return $purchase;
}

it('refunds a subscription invoice and reverses its held commission', function () {
    $sub = Subscription::factory()->create(['creem_subscription_id' => 'sub_test']);
    $invoice = Invoice::factory()->for($sub)->create(['status' => 'paid', 'amount' => 999, 'creem_transaction_id' => 'tran_r']);
    $commission = Commission::create([
        'referral_id' => Referral::factory()->create(['referred_user_id' => $sub->user_id])->id,
        'invoice_id' => $invoice->id, 'amount' => 100, 'tier_percent' => '20.00', 'installment_no' => 1, 'hold_until' => now()->addMonth(),
    ]);
    sendCreem(creemRefund(['id' => 'tran_r', 'amount' => 999, 'refunded_amount' => 999]))->assertOk();
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Refunded)
        ->and($commission->fresh()->status)->toBe(CommissionStatus::Reversed);
});

it('leaves a partially refunded invoice for staff', function () {
    $staff = User::factory()->create(['is_staff' => true]);
    $sub = Subscription::factory()->create(['creem_subscription_id' => 'sub_test']);
    $invoice = Invoice::factory()->for($sub)->create(['status' => 'paid', 'amount' => 999, 'creem_transaction_id' => 'tran_p']);
    sendCreem(creemRefund(['id' => 'tran_p', 'amount' => 999, 'refunded_amount' => 500]))->assertOk();
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid);
    Notification::assertSentTo($staff, BillingNeedsReview::class);
});

it('takes refunded Credits back once, across replays', function () {
    $user = User::factory()->create();
    $purchase = paidTopUp($user);
    $event = creemRefund(['id' => 'tran_c', 'amount' => 1000, 'refunded_amount' => 1000], ['request_id' => $purchase->ulid]);
    sendCreem($event)->assertOk();
    $event['id'] = 'evt_redelivered';
    sendCreem($event)->assertOk();
    expect(Wallet::balance($user, Wallet::CREDITS))->toBe(0)
        ->and($purchase->fresh()->status)->toBe('refunded');
});

it('takes a partial refund in proportion and the rest on a second refund', function () {
    $user = User::factory()->create();
    $purchase = paidTopUp($user);
    sendCreem(creemRefund(['id' => 'tran_c', 'amount' => 1000, 'refunded_amount' => 400], ['request_id' => $purchase->ulid], 'ref_a'))->assertOk();
    expect(Wallet::balance($user, Wallet::CREDITS))->toBe(60000);
    sendCreem(creemRefund(['id' => 'tran_c', 'amount' => 1000, 'refunded_amount' => 1000], ['request_id' => $purchase->ulid], 'ref_b'))->assertOk();
    expect(Wallet::balance($user, Wallet::CREDITS))->toBe(0);
});

it('never takes the Credits balance negative and tells staff the shortfall', function () {
    $staff = User::factory()->create(['is_staff' => true]);
    $user = User::factory()->create();
    $purchase = paidTopUp($user);
    Wallet::debit($user, WalletTransactionType::CreditsSend, 70000, null, null, null, Wallet::CREDITS);
    sendCreem(creemRefund(['id' => 'tran_c', 'amount' => 1000, 'refunded_amount' => 1000], ['request_id' => $purchase->ulid]))->assertOk();
    expect(Wallet::balance($user, Wallet::CREDITS))->toBe(0);
    Notification::assertSentTo($staff, BillingNeedsReview::class, fn ($n) => str_contains($n->reason, '70000'));
});

it('tells staff about a dispute', function () {
    $staff = User::factory()->create(['is_staff' => true]);
    sendCreem(['id' => 'evt_dispute', 'eventType' => 'dispute.created', 'object' => ['id' => 'dis_1']])->assertOk();
    Notification::assertSentTo($staff, BillingNeedsReview::class);
});
