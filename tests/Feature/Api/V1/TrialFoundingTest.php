<?php

use App\Enums\InvoiceStatus;
use App\Enums\SubscriptionStatus;
use App\Jobs\ExpireSubscriptions;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Analytics;
use App\Services\CreemService;
use App\Support\Credits;
use App\Support\Trial;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/*
 * The 7-day Pro trial (Creem's product-level trial, card required, on its
 * own products) and Founding 100 (a discount code on yearly checkouts).
 * Creem's shapes are from its official SDK (creem@1.13.0): event
 * `subscription.trialing`, status `trialing`, `current_period_end_date`,
 * checkout field `discount_code`.
 */

beforeEach(function () {
    config([
        'services.creem.products.pro_monthly_trial' => 'prod_pro_monthly_trial',
        'services.creem.products.pro_yearly_trial' => 'prod_pro_yearly_trial',
        'services.creem.founding_code' => 'FOUNDING100',
    ]);
    Notification::fake();
    Http::preventStrayRequests();
    $this->analytics = Analytics::fake();
});

/** Creem's checkout endpoint; `$refuseCode` answers 400 to any body with a discount code. */
function fakeCreemCheckouts(bool $refuseCode = false): void
{
    $n = 0;
    Http::fake(['test-api.creem.io/*' => function (HttpRequest $r) use (&$n, $refuseCode) {
        if (str_ends_with($r->url(), '/cancel')) {
            return Http::response(['id' => 'sub_trial', 'status' => 'scheduled_cancel']);
        }
        if ($refuseCode && isset($r['discount_code'])) {
            return Http::response(['message' => 'Discount code has reached its maximum redemptions'], 400);
        }

        return Http::response(['id' => 'ch_'.++$n, 'checkout_url' => 'https://checkout.creem.io/'.$n]);
    }]);
}

/** @return list<HttpRequest> */
function creemCheckoutRequests(): array
{
    return Http::recorded(fn (HttpRequest $r) => str_ends_with($r->url(), '/checkouts'))
        ->map(fn ($pair) => $pair[0])->values()->all();
}

function trialHook(array $event)
{
    $body = json_encode($event, JSON_THROW_ON_ERROR);

    return test()->call('POST', '/api/v1/webhooks/creem', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_CREEM_SIGNATURE' => hash_hmac('sha256', $body, config('services.creem.webhook_secret')),
    ], $body);
}

/** An eligible owner who has just opened the Pro trial checkout. */
function trialCheckout(string $period = 'monthly'): Subscription
{
    fakeCreemCheckouts();
    $user = User::factory()->create();
    test()->actingAs($user)->postJson('/api/v1/me/subscription/checkout', ['plan' => 'pro', 'period' => $period])
        ->assertCreated();

    return $user->subscriptions()->sole();
}

function trialingEvent(Subscription $s, string $id = 'evt_trial', array $over = []): array
{
    return ['id' => $id, 'eventType' => 'subscription.trialing', 'object' => $over + [
        'id' => 'sub_trial', 'object' => 'subscription', 'status' => 'trialing',
        'product' => 'prod_pro_monthly_trial', 'customer' => 'cust_trial',
        'metadata' => ['subscription' => $s->ulid],
        'current_period_start_date' => now()->toIso8601String(),
        'current_period_end_date' => now()->addDays(7)->toIso8601String(),
    ]];
}

function trialPaidEvent(Subscription $s, string $tx, int $amount, string $id): array
{
    return ['id' => $id, 'eventType' => 'subscription.paid', 'object' => [
        'id' => 'sub_trial', 'product' => 'prod_pro_monthly_trial', 'customer' => 'cust_trial',
        'metadata' => ['subscription' => $s->ulid],
        'last_transaction_id' => $tx,
        'last_transaction' => ['id' => $tx, 'status' => 'paid', 'amount' => $amount, 'currency' => 'USD'],
        'current_period_start_date' => now()->toIso8601String(),
        'current_period_end_date' => now()->addMonth()->toIso8601String(),
    ]];
}

describe('checkout', function () {
    it('sends an eligible owner to the Pro trial product and says so', function () {
        $sub = trialCheckout();

        $requests = creemCheckoutRequests();
        expect($requests)->toHaveCount(1)
            ->and($requests[0]['product_id'])->toBe('prod_pro_monthly_trial')
            ->and($sub->status)->toBe(SubscriptionStatus::Expired)
            ->and($sub->user->plan()->key->value)->toBe('free');
        $this->analytics->assertCapturedOnce('checkout_started', fn ($p) => $p['trial'] === true && $p['plan'] === 'pro');
    });

    it('answers with the trial length', function () {
        fakeCreemCheckouts();
        $this->actingAs(User::factory()->create())
            ->postJson('/api/v1/me/subscription/checkout', ['plan' => 'pro', 'period' => 'monthly'])
            ->assertCreated()
            ->assertJsonPath('data.trial_days', 7)
            ->assertJsonPath('data.amount', 999);
    });

    it('sends an owner who had a paid plan to the normal product', function () {
        fakeCreemCheckouts();
        $user = User::factory()->create();
        $old = Subscription::factory()->for($user)->create(['status' => SubscriptionStatus::Expired, 'creem_subscription_id' => null]);
        $old->invoices()->create(['number' => Invoice::nextNumber(), 'amount' => 999, 'currency' => 'USD', 'status' => InvoiceStatus::Paid]);

        $this->actingAs($user)->postJson('/api/v1/me/subscription/checkout', ['plan' => 'pro', 'period' => 'monthly'])
            ->assertCreated()->assertJsonPath('data.trial_days', null);

        expect(creemCheckoutRequests()[0]['product_id'])->toBe('prod_pro_monthly');
        $this->analytics->assertCapturedOnce('checkout_started', fn ($p) => $p['trial'] === false);
    });

    it('sends an owner who already had a trial to the normal product', function () {
        fakeCreemCheckouts();
        $user = User::factory()->create();
        Subscription::factory()->for($user)->create(['status' => SubscriptionStatus::Expired, 'creem_subscription_id' => 'sub_old_trial']);

        expect(Trial::eligible($user))->toBeFalse();
        $this->actingAs($user)->postJson('/api/v1/me/subscription/checkout', ['plan' => 'pro', 'period' => 'monthly'])->assertCreated();
        expect(creemCheckoutRequests()[0]['product_id'])->toBe('prod_pro_monthly');
    });

    it('keeps an owner eligible after an abandoned checkout', function () {
        fakeCreemCheckouts();
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/api/v1/me/subscription/checkout', ['plan' => 'max', 'period' => 'monthly'])->assertCreated();

        expect(Trial::eligible($user))->toBeTrue();
        $this->postJson('/api/v1/me/subscription/checkout', ['plan' => 'pro', 'period' => 'monthly'])->assertCreated();
        expect(creemCheckoutRequests()[1]['product_id'])->toBe('prod_pro_monthly_trial');
    });

    it('never trials Max', function () {
        fakeCreemCheckouts();
        $this->actingAs(User::factory()->create())
            ->postJson('/api/v1/me/subscription/checkout', ['plan' => 'max', 'period' => 'monthly'])->assertCreated();

        expect(creemCheckoutRequests()[0]['product_id'])->toBe('prod_max_monthly');
    });

    it('uses the normal product when the trial product is not configured', function () {
        config(['services.creem.products.pro_monthly_trial' => null, 'services.creem.products.pro_yearly_trial' => null]);
        fakeCreemCheckouts();
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/v1/me/subscription')->assertJsonPath('data.trial.eligible', false);
        $this->postJson('/api/v1/me/subscription/checkout', ['plan' => 'pro', 'period' => 'monthly'])->assertCreated();
        expect(creemCheckoutRequests()[0]['product_id'])->toBe('prod_pro_monthly');
    });
});

describe('founding 100', function () {
    it('sends the code on every yearly checkout', function (string $plan, string $product) {
        fakeCreemCheckouts();
        $user = User::factory()->create();
        // Already had a trial, so Pro yearly is the normal product here.
        Subscription::factory()->for($user)->create(['status' => SubscriptionStatus::Expired, 'creem_subscription_id' => 'sub_x']);

        $this->actingAs($user)->postJson('/api/v1/me/subscription/checkout', ['plan' => $plan, 'period' => 'yearly'])->assertCreated();

        $sent = creemCheckoutRequests()[0];
        expect($sent['product_id'])->toBe($product)->and($sent['discount_code'])->toBe('FOUNDING100');
    })->with([['pro', 'prod_pro_yearly'], ['max', 'prod_max_yearly']]);

    it('sends the code with the yearly trial too', function () {
        trialCheckout('yearly');

        $sent = creemCheckoutRequests()[0];
        expect($sent['product_id'])->toBe('prod_pro_yearly_trial')->and($sent['discount_code'])->toBe('FOUNDING100');
    });

    it('never sends the code on a monthly checkout', function (string $plan) {
        fakeCreemCheckouts();
        $this->actingAs(User::factory()->create())
            ->postJson('/api/v1/me/subscription/checkout', ['plan' => $plan, 'period' => 'monthly'])->assertCreated();

        expect(creemCheckoutRequests()[0]->data())->not->toHaveKey('discount_code');
    })->with(['pro', 'max']);

    it('opens the checkout without the code when Creem refuses it, and logs it', function () {
        Log::spy();
        fakeCreemCheckouts(refuseCode: true);

        $this->actingAs(User::factory()->create())
            ->postJson('/api/v1/me/subscription/checkout', ['plan' => 'max', 'period' => 'yearly'])
            ->assertCreated()
            ->assertJsonPath('data.checkout_url', 'https://checkout.creem.io/1');

        $sent = creemCheckoutRequests();
        expect($sent)->toHaveCount(2)
            ->and($sent[0]['discount_code'])->toBe('FOUNDING100')
            ->and($sent[1]->data())->not->toHaveKey('discount_code')
            ->and(Invoice::query()->sole()->creem_checkout_id)->toBe('ch_1');
        Log::shouldHaveReceived('warning')->withArgs(fn ($m, $c) => $m === 'billing.discount_code_rejected' && $c['status'] === 400)->once();
    });

    it('does not retry when Creem itself is down', function () {
        Http::fake(['test-api.creem.io/*' => Http::response(['message' => 'boom'], 503)]);

        $this->actingAs(User::factory()->create())
            ->postJson('/api/v1/me/subscription/checkout', ['plan' => 'max', 'period' => 'yearly'])
            ->assertServerError();

        expect(creemCheckoutRequests())->toHaveCount(1);
    });

    it('sends nothing when the code is not configured', function () {
        config(['services.creem.founding_code' => '']);
        fakeCreemCheckouts();
        $this->actingAs(User::factory()->create())
            ->postJson('/api/v1/me/subscription/checkout', ['plan' => 'max', 'period' => 'yearly'])->assertCreated();

        expect(creemCheckoutRequests()[0]->data())->not->toHaveKey('discount_code');
    });
});

describe('the subscription screen', function () {
    it('tells the app about the trial and Founding 100', function () {
        $this->actingAs(User::factory()->create())->getJson('/api/v1/me/subscription')
            ->assertOk()
            ->assertJsonPath('data.trial', ['eligible' => true, 'days' => 7])
            ->assertJsonPath('data.founding', ['active' => true, 'percent' => 30, 'applies_to' => 'yearly']);
    });

    it('shows a trialing subscription with its end', function () {
        $sub = trialCheckout();
        trialHook(trialingEvent($sub))->assertOk();

        $this->actingAs($sub->user)->getJson('/api/v1/me/subscription')
            ->assertJsonPath('data.plan.key', 'pro')
            ->assertJsonPath('data.subscription.status', 'trialing')
            ->assertJsonPath('data.subscription.trial_ends_at', $sub->fresh()->current_period_end->toJSON())
            ->assertJsonPath('data.trial.eligible', false);
    });
});

describe('webhooks', function () {
    it('starts the trial: Pro limits, trialing, trial_started once', function () {
        $sub = trialCheckout();
        trialHook(['id' => 'evt_co', 'eventType' => 'checkout.completed', 'object' => [
            'id' => 'ch_1', 'request_id' => $sub->invoices()->sole()->number,
            'product' => 'prod_pro_monthly_trial', 'subscription' => 'sub_trial', 'customer' => 'cust_trial',
        ]])->assertOk();

        trialHook(trialingEvent($sub))->assertOk();
        trialHook(trialingEvent($sub, 'evt_trial_again'))->assertOk();

        $sub->refresh();
        expect($sub->status)->toBe(SubscriptionStatus::Trialing)
            ->and($sub->creem_subscription_id)->toBe('sub_trial')
            ->and($sub->current_period_end->isSameDay(now()->addDays(7)))->toBeTrue()
            ->and($sub->user->plan()->key->value)->toBe('pro')
            ->and(Credits::monthlyAmount($sub->user))->toBe($sub->user->plan()->quota('credits_monthly'))
            ->and($sub->invoices()->where('status', InvoiceStatus::Paid)->exists())->toBeFalse();
        $this->analytics->assertCapturedOnce('trial_started', fn ($p) => $p === ['plan' => 'pro', 'period' => 'monthly', 'trial_days' => 7]);
        $this->analytics->assertNotCaptured('subscription_started');
    });

    it('starts the trial from checkout.completed when the trialing event is lost', function () {
        $sub = trialCheckout();
        trialHook(['id' => 'evt_co', 'eventType' => 'checkout.completed', 'object' => [
            'id' => 'ch_1', 'request_id' => $sub->invoices()->sole()->number,
            'product' => 'prod_pro_monthly_trial', 'customer' => 'cust_trial',
            'subscription' => trialingEvent($sub)['object'],
        ]])->assertOk();
        trialHook(trialingEvent($sub))->assertOk();

        expect($sub->fresh()->status)->toBe(SubscriptionStatus::Trialing);
        $this->analytics->assertCapturedOnce('trial_started');
    });

    it('ignores the $0 card check at the start of a trial', function () {
        $sub = trialCheckout();
        trialHook(trialingEvent($sub))->assertOk();
        trialHook(trialPaidEvent($sub, 'tran_zero', 0, 'evt_zero'))->assertOk();

        expect($sub->fresh()->status)->toBe(SubscriptionStatus::Trialing)
            ->and($sub->invoices()->where('status', InvoiceStatus::Paid)->exists())->toBeFalse();
        $this->analytics->assertNotCaptured('subscription_started');
    });

    it('turns into the paid plan at the first real charge', function () {
        $sub = trialCheckout();
        trialHook(trialingEvent($sub))->assertOk();
        $this->travel(7)->days();

        trialHook(trialPaidEvent($sub, 'tran_first', 999, 'evt_paid'))->assertOk();
        trialHook(trialPaidEvent($sub, 'tran_first', 999, 'evt_paid_replay'))->assertOk();

        $sub->refresh();
        expect($sub->status)->toBe(SubscriptionStatus::Active)
            ->and($sub->plan->key->value)->toBe('pro')
            ->and($sub->invoices()->sole()->status)->toBe(InvoiceStatus::Paid);
        $this->analytics->assertCapturedOnce('subscription_started', fn ($p) => $p['amount_usd'] === 9.99);
    });

    it('goes back to Free at the trial end when cancelled, never charged', function () {
        $sub = trialCheckout();
        trialHook(trialingEvent($sub))->assertOk();
        $user = $sub->user;

        $this->actingAs($user)->postJson('/api/v1/me/subscription/cancel')
            ->assertOk()
            ->assertJsonPath('data.ends_at_period_end', true);
        trialHook(['id' => 'evt_sc', 'eventType' => 'subscription.scheduled_cancel', 'object' => trialingEvent($sub)['object']])->assertOk();

        // Still Pro until the trial would have charged…
        $this->travel(6)->days();
        expect($user->fresh()->plan()->key->value)->toBe('pro');

        // …then Free, before any job or event has run.
        $this->travel(2)->days();
        expect($user->fresh()->plan()->key->value)->toBe('free');

        (new ExpireSubscriptions)->handle(app(CreemService::class));
        expect($sub->fresh()->status)->toBe(SubscriptionStatus::Expired)
            ->and(Invoice::query()->where('status', InvoiceStatus::Paid)->exists())->toBeFalse();

        // A late trialing replay does not bring it back.
        trialHook(trialingEvent($sub, 'evt_late'))->assertOk();
        expect($sub->fresh()->status)->toBe(SubscriptionStatus::Expired)
            ->and(Trial::eligible($user))->toBeFalse();
    });

    it('ends a cancelled trial on Creem\'s canceled event too', function () {
        $sub = trialCheckout();
        trialHook(trialingEvent($sub))->assertOk();
        $this->travel(8)->days();

        trialHook(['id' => 'evt_cancel', 'eventType' => 'subscription.canceled', 'object' => trialingEvent($sub)['object']])->assertOk();

        expect($sub->fresh()->status)->toBe(SubscriptionStatus::Expired)
            ->and($sub->user->fresh()->plan()->key->value)->toBe('free');
    });

    it('refuses a plan change during the trial', function () {
        $sub = trialCheckout();
        trialHook(trialingEvent($sub))->assertOk();

        $this->actingAs($sub->user)->postJson('/api/v1/me/subscription/checkout', ['plan' => 'max', 'period' => 'monthly'])
            ->assertUnprocessable()->assertJsonValidationErrors('plan');
    });
});
