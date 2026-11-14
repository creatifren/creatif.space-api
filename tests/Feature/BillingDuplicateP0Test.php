<?php

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Analytics;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/*
 * P0 14 Nov (b202611141316, b202611141138): the owner's old Creem trial
 * (cancelled 13 Nov, period over) still read 'trialing', so his paid Pro
 * checkout was logged as a duplicate and never activated — charged $9.99,
 * left on Free. And plan:comp aborted because Creem answered 400 for that
 * already-cancelled trial. Replayed with the webhook payloads Creem sends.
 */

beforeEach(function () {
    Notification::fake();
    Analytics::fake();
    Http::preventStrayRequests();
    config(['services.creem.api_key' => 'creem_test_key', 'services.creem.test_mode' => true]);
});

function p0Webhook(array $event)
{
    $body = json_encode($event, JSON_THROW_ON_ERROR);

    return test()->call('POST', '/api/v1/webhooks/creem', [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        'HTTP_CREEM_SIGNATURE' => hash_hmac('sha256', $body, (string) config('services.creem.webhook_secret')),
    ], $body);
}

function p0Paid(Subscription $row, string $creemId, string $tx = 'tran_p0'): array
{
    return [
        'id' => 'evt_'.$tx, 'eventType' => 'subscription.paid',
        'object' => [
            'id' => $creemId, 'product' => config('services.creem.products.pro_monthly'), 'customer' => 'cust_owner',
            'status' => 'active', 'metadata' => ['subscription' => $row->ulid],
            'last_transaction_id' => $tx,
            'last_transaction' => ['id' => $tx, 'status' => 'paid', 'amount' => 999, 'currency' => 'USD'],
            'current_period_start_date' => now()->subHour()->toIso8601String(),
            'current_period_end_date' => now()->addMonth()->toIso8601String(),
        ],
    ];
}

/** The owner's state: the old trial (cancelled, period over, still 'trialing') and the new checkout row. */
function p0Owner(): array
{
    $user = User::factory()->create(['email' => 'owner@example.test']);
    $trial = Subscription::factory()->for($user)->create([
        'creem_subscription_id' => 'sub_2E4w', 'status' => 'trialing',
        'cancelled_at' => now()->subDay(), 'current_period_end' => now()->subHours(2),
    ]);
    $checkout = Subscription::factory()->for($user)->create([
        'creem_subscription_id' => null, 'status' => 'expired', 'current_period_start' => null, 'current_period_end' => null,
    ]);

    return [$user, $trial, $checkout];
}

it('a cancelled trial past its period is not entitled, whatever its status still says', function () {
    [$user, $trial] = p0Owner();

    expect($trial->fresh()->isEntitled())->toBeFalse()
        ->and(Subscription::query()->whereKey($trial->id)->entitled()->exists())->toBeFalse()
        ->and($user->activeSubscription())->toBeNull();
});

it('a paid checkout activates even with the stale cancelled trial; the trial is closed', function () {
    [$user, $trial, $checkout] = p0Owner();
    Log::spy();

    p0Webhook(p0Paid($checkout, 'sub_PkCG'))->assertOk();

    expect($checkout->fresh()->status->value)->toBe('active')
        ->and($user->fresh()->plan()->key->value)->toBe('pro')
        ->and($user->fresh()->activeSubscription()->id)->toBe($checkout->id)
        ->and($trial->fresh()->status->value)->toBe('expired');
    Log::shouldNotHaveReceived('warning', fn ($m) => $m === 'billing.duplicate_subscription_paid');
});

it('a genuine duplicate (two live paid plans): the new paid one is active, the other is logged for a manual refund', function () {
    $user = User::factory()->create();
    $live = Subscription::factory()->for($user)->create(['creem_subscription_id' => 'sub_live']);
    $live->invoices()->create(['number' => Invoice::nextNumber(), 'amount' => 999, 'currency' => 'USD', 'status' => InvoiceStatus::Paid, 'creem_transaction_id' => 'tran_old', 'paid_at' => now()->subDays(3)]);
    $second = Subscription::factory()->for($user)->create(['creem_subscription_id' => null, 'status' => 'expired', 'current_period_start' => null, 'current_period_end' => null]);
    Log::spy();

    p0Webhook(p0Paid($second, 'sub_second', 'tran_second'))->assertOk();

    expect($second->fresh()->status->value)->toBe('active')
        ->and($user->fresh()->activeSubscription()->id)->toBe($second->id)
        ->and($live->fresh()->status->value)->toBe('active'); // left for the refund, not touched
    Log::shouldHaveReceived('warning')->withArgs(fn ($m, $ctx) => $m === 'billing.duplicate_subscription_paid'
        && $ctx['other_subscription'] === $live->ulid && str_contains($ctx['action'], 'refund'))->once();
});

it('plan:comp on a trial Creem already cancelled: Creem says so, the comp is written', function () {
    [$user, $trial] = p0Owner();
    // Still "running" on our side (period not over) so plan:comp tries to cancel it.
    $trial->update(['current_period_end' => now()->addHours(3)]);
    Http::fake([
        '*/v1/subscriptions/sub_2E4w/cancel' => Http::response(['error' => 'Bad Request', 'message' => 'Subscription already canceled'], 400),
        '*/v1/subscriptions*' => Http::response(['id' => 'sub_2E4w', 'status' => 'canceled']),
    ]);

    $code = Artisan::call('plan:comp', ['email' => 'owner@example.test', '--plan' => 'pro', '--until' => '2027-11-14']);
    $out = trim(Artisan::output());

    expect($code)->toBe(0)
        ->and($out)->toContain('already ended at Creem (sub_2E4w)')
        ->and($user->fresh()->activeSubscription()->plan_source)->toBe(Subscription::SOURCE_STAFF_COMP)
        ->and($trial->fresh()->status->value)->toBe('expired');
});

it('plan:comp still aborts on any other Creem error, changing nothing', function () {
    [$user, $trial] = p0Owner();
    $trial->update(['current_period_end' => now()->addHours(3)]);
    Http::fake([
        '*/v1/subscriptions/sub_2E4w/cancel' => Http::response(['error' => 'oops'], 500),
        '*/v1/subscriptions*' => Http::response(['id' => 'sub_2E4w', 'status' => 'trialing']),
    ]);

    expect(Artisan::call('plan:comp', ['email' => 'owner@example.test', '--plan' => 'pro', '--until' => '2027-11-14']))->toBe(1)
        ->and(Artisan::output())->toContain('nothing was changed');
    expect(Subscription::query()->where('plan_source', Subscription::SOURCE_STAFF_COMP)->exists())->toBeFalse();
});

/** The prod state after the bug: paid invoice on row 15, row 15 never activated (later cancelled + expired). */
function p0Broken(): array
{
    [$user, $trial, $checkout] = p0Owner();
    $object = p0Paid($checkout, 'sub_PkCG')['object'];
    $checkout->update(['creem_subscription_id' => 'sub_PkCG', 'status' => 'expired', 'cancelled_at' => now()->subMinutes(30)]);
    $invoice = $checkout->invoices()->create(['number' => 'INV-2026-000007', 'amount' => 999, 'currency' => 'USD', 'status' => InvoiceStatus::Paid,
        'creem_transaction_id' => 'tran_p0', 'paid_at' => now()->subHour(), 'raw_notification' => $object]);

    return [$user, $trial, $checkout, $invoice];
}

it('billing:reconcile --dry-run lists the account and changes nothing', function () {
    [$user, $trial, $checkout] = p0Broken();
    $other = User::factory()->create();
    Subscription::factory()->for($other)->create();

    expect(Artisan::call('billing:reconcile', ['--dry-run' => true]))->toBe(0);
    $out = Artisan::output();

    expect($out)->toContain("user {$user->id}: would activate subscription {$checkout->id} (sub_PkCG, Pro monthly")
        ->toContain('from paid invoice INV-2026-000007')
        ->not->toContain("user {$other->id}");
    expect($checkout->fresh()->status->value)->toBe('expired')
        ->and($user->fresh()->plan()->key->value)->toBe('free');
    Http::assertNothingSent();
});

it('billing:reconcile for the account activates the paid subscription (keeps the cancellation: no renewal); a rerun finds nothing', function () {
    [$user, $trial, $checkout] = p0Broken();

    expect(Artisan::call('billing:reconcile', ['email' => 'owner@example.test']))->toBe(0)
        ->and(Artisan::output())->toContain("user {$user->id}: activated subscription {$checkout->id}");

    $fresh = $checkout->fresh();
    expect($fresh->status->value)->toBe('active')
        ->and($fresh->current_period_end->isFuture())->toBeTrue()
        ->and($fresh->cancelled_at)->not->toBeNull()
        ->and($user->fresh()->plan()->key->value)->toBe('pro')
        ->and($trial->fresh()->status->value)->toBe('expired');

    Artisan::call('billing:reconcile', ['email' => 'owner@example.test']);
    expect(Artisan::output())->toContain('nothing to reconcile (plan Pro)');
    Http::assertNothingSent();
});

it('billing:reconcile leaves refunded or run-out payments alone', function () {
    [, , , $invoice] = p0Broken();
    $invoice->update(['status' => InvoiceStatus::Refunded]);

    Artisan::call('billing:reconcile', ['--dry-run' => true]);

    expect(Artisan::output())->toContain('No account is charged without its plan.');
});
