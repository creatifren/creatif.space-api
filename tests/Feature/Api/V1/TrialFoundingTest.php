<?php

use App\Enums\InvoiceStatus;
use App\Enums\SubscriptionStatus;
use App\Jobs\ExpireSubscriptions;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Analytics;
use App\Services\CreemService;
use App\Support\Credits;
use App\Support\CreemTrial;
use App\Support\Trial;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/*
 * The 7-day Pro trial lives on Creem's Pro products (7 days, card taken);
 * owners who already had a trial or a paid plan go to no-trial twins.
 * Founding 100 is a discount code on yearly checkouts. Creem's shapes are
 * from its official SDK (creem@1.13.0): event `subscription.trialing`,
 * status `trialing`, `current_period_end_date`, checkout `discount_code`;
 * a trial opens with a $0 `subscription.paid`, as seen in the live account.
 */

beforeEach(function () {
    config([
        'services.creem.products.pro_monthly_notrial' => 'prod_pro_monthly_notrial',
        'services.creem.products.pro_yearly_notrial' => 'prod_pro_yearly_notrial',
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
        'product' => 'prod_pro_monthly', 'customer' => 'cust_trial',
        'metadata' => ['subscription' => $s->ulid],
        'current_period_start_date' => now()->toIso8601String(),
        'current_period_end_date' => now()->addDays(7)->toIso8601String(),
    ]];
}

function trialPaidEvent(Subscription $s, string $tx, int $amount, string $id, ?string $status = 'active'): array
{
    return ['id' => $id, 'eventType' => 'subscription.paid', 'object' => [
        'id' => 'sub_trial', 'status' => $status, 'product' => 'prod_pro_monthly', 'customer' => 'cust_trial',
        'metadata' => ['subscription' => $s->ulid],
        'last_transaction_id' => $tx,
        'last_transaction' => ['id' => $tx, 'status' => 'paid', 'amount' => $amount, 'currency' => 'USD'],
        'current_period_start_date' => now()->toIso8601String(),
        'current_period_end_date' => ($status === 'trialing' ? now()->addDays(7) : now()->addMonth())->toIso8601String(),
    ]];
}

/** A clean fake: the checkout fake answers every Creem URL, so it is swapped out. */
function freshCreemFake(array $stubs): void
{
    Http::swap(new HttpFactory);
    Http::preventStrayRequests();
    Http::fake($stubs);
}

function bindCheckout(Subscription $s): void
{
    trialHook(['id' => 'evt_co_'.$s->ulid, 'eventType' => 'checkout.completed', 'object' => [
        'id' => 'ch_1', 'request_id' => $s->invoices()->sole()->number,
        'product' => 'prod_pro_monthly', 'subscription' => 'sub_trial', 'customer' => 'cust_trial',
    ]])->assertOk();
}

describe('what main did with a trial (the bug this fixes first)', function () {
    it('gives Pro during the trial when Creem opens it with a $0 paid event', function () {
        $sub = trialCheckout();
        bindCheckout($sub);

        // Creem's first event of a trial: "paid", $0, status trialing. Main
        // answered 422 here, so the user stayed Free for the whole trial.
        trialHook(trialPaidEvent($sub, 'tran_zero', 0, 'evt_zero', 'trialing'))->assertOk();

        $sub->refresh();
        expect($sub->status)->toBe(SubscriptionStatus::Trialing)
            ->and($sub->user->plan()->key->value)->toBe('pro')
            ->and($sub->current_period_end->isSameDay(now()->addDays(7)))->toBeTrue()
            ->and($sub->invoices()->where('status', InvoiceStatus::Paid)->exists())->toBeFalse();
        $this->analytics->assertCapturedOnce('trial_started');
        $this->analytics->assertNotCaptured('subscription_started');
    });

    it('lets the owner cancel in the app during the trial (main: "no paid plan")', function () {
        $sub = trialCheckout();
        bindCheckout($sub);
        trialHook(trialPaidEvent($sub, 'tran_zero', 0, 'evt_zero', 'trialing'))->assertOk();

        $this->actingAs($sub->user)->postJson('/api/v1/me/subscription/cancel')
            ->assertOk()->assertJsonPath('data.status', 'trialing')->assertJsonPath('data.ends_at_period_end', true);
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/subscriptions/sub_trial/cancel'));
    });

    it('answers 200 to a $0 paid event that is not a trial, and records nothing', function () {
        $sub = trialCheckout();
        bindCheckout($sub);

        trialHook(trialPaidEvent($sub, 'tran_zero', 0, 'evt_zero', 'active'))->assertOk();

        expect($sub->fresh()->status)->toBe(SubscriptionStatus::Expired)
            ->and($sub->invoices()->where('status', InvoiceStatus::Paid)->exists())->toBeFalse();
    });
});

describe('checkout', function () {
    it('sends an eligible owner to Pro, which carries the trial, and says so', function () {
        $sub = trialCheckout();

        $requests = creemCheckoutRequests();
        expect($requests)->toHaveCount(1)
            ->and($requests[0]['product_id'])->toBe('prod_pro_monthly')
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

    it('sends an owner who had a paid plan to the no-trial Pro', function () {
        fakeCreemCheckouts();
        $user = User::factory()->create();
        $old = Subscription::factory()->for($user)->create(['status' => SubscriptionStatus::Expired, 'creem_subscription_id' => null]);
        $old->invoices()->create(['number' => Invoice::nextNumber(), 'amount' => 999, 'currency' => 'USD', 'status' => InvoiceStatus::Paid]);

        $this->actingAs($user)->postJson('/api/v1/me/subscription/checkout', ['plan' => 'pro', 'period' => 'monthly'])
            ->assertCreated()->assertJsonPath('data.trial_days', null);

        expect(creemCheckoutRequests()[0]['product_id'])->toBe('prod_pro_monthly_notrial');
        $this->analytics->assertCapturedOnce('checkout_started', fn ($p) => $p['trial'] === false);
    });

    it('sends an owner who already had a trial to the no-trial Pro', function () {
        fakeCreemCheckouts();
        $user = User::factory()->create();
        Subscription::factory()->for($user)->create(['status' => SubscriptionStatus::Expired, 'creem_subscription_id' => 'sub_old_trial']);

        expect(Trial::eligible($user))->toBeFalse();
        $this->actingAs($user)->postJson('/api/v1/me/subscription/checkout', ['plan' => 'pro', 'period' => 'yearly'])->assertCreated();
        expect(creemCheckoutRequests()[0]['product_id'])->toBe('prod_pro_yearly_notrial');
    });

    it('falls back to Pro and logs a possible repeat trial when no no-trial product is set', function () {
        Log::spy();
        config(['services.creem.products.pro_monthly_notrial' => '']);
        fakeCreemCheckouts();
        $user = User::factory()->create();
        Subscription::factory()->for($user)->create(['status' => SubscriptionStatus::Expired, 'creem_subscription_id' => 'sub_old_trial']);

        $this->actingAs($user)->postJson('/api/v1/me/subscription/checkout', ['plan' => 'pro', 'period' => 'monthly'])
            ->assertCreated()->assertJsonPath('data.trial_days', 7);

        expect(creemCheckoutRequests()[0]['product_id'])->toBe('prod_pro_monthly');
        Log::shouldHaveReceived('warning')->withArgs(fn ($m, $c) => $m === 'billing.repeat_trial_possible'
            && $c['missing'] === 'CREEM_PRODUCT_PRO_MONTHLY_NOTRIAL')->once();
    });

    it('keeps an owner eligible after an abandoned checkout', function () {
        fakeCreemCheckouts();
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/api/v1/me/subscription/checkout', ['plan' => 'max', 'period' => 'monthly'])->assertCreated();

        expect(Trial::eligible($user))->toBeTrue();
        $this->postJson('/api/v1/me/subscription/checkout', ['plan' => 'pro', 'period' => 'monthly'])->assertCreated();
        expect(creemCheckoutRequests()[1]['product_id'])->toBe('prod_pro_monthly');
    });

    it('never trials Max', function () {
        fakeCreemCheckouts();
        $this->actingAs(User::factory()->create())
            ->postJson('/api/v1/me/subscription/checkout', ['plan' => 'max', 'period' => 'monthly'])
            ->assertCreated()->assertJsonPath('data.trial_days', null);

        expect(creemCheckoutRequests()[0]['product_id'])->toBe('prod_max_monthly');
    });

    it('moves a paying owner to Pro without a trial', function () {
        Http::fake(['test-api.creem.io/*' => Http::response(['id' => 'sub_paid'])]);
        $sub = Subscription::factory()->create([
            'plan_id' => Plan::query()->where('key', 'max')->value('id'),
            'status' => SubscriptionStatus::Active, 'creem_subscription_id' => 'sub_paid',
        ]);

        $this->actingAs($sub->user)->postJson('/api/v1/me/subscription/checkout', ['plan' => 'pro', 'period' => 'yearly'])->assertStatus(202);

        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/subscriptions/sub_paid/upgrade')
            && $r['product_id'] === 'prod_pro_yearly_notrial');
    });
});

describe('founding 100', function () {
    it('sends the code on every yearly checkout', function (string $plan, string $product) {
        fakeCreemCheckouts();
        $user = User::factory()->create();
        // Already had a trial, so Pro yearly is the no-trial twin here.
        Subscription::factory()->for($user)->create(['status' => SubscriptionStatus::Expired, 'creem_subscription_id' => 'sub_x']);

        $this->actingAs($user)->postJson('/api/v1/me/subscription/checkout', ['plan' => $plan, 'period' => 'yearly'])->assertCreated();

        $sent = creemCheckoutRequests()[0];
        expect($sent['product_id'])->toBe($product)->and($sent['discount_code'])->toBe('FOUNDING100');
    })->with([['pro', 'prod_pro_yearly_notrial'], ['max', 'prod_max_yearly']]);

    it('sends the code with the yearly trial too', function () {
        trialCheckout('yearly');

        $sent = creemCheckoutRequests()[0];
        expect($sent['product_id'])->toBe('prod_pro_yearly')->and($sent['discount_code'])->toBe('FOUNDING100');
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
    it('starts the trial on subscription.trialing: Pro limits, trial_started once', function () {
        $sub = trialCheckout();
        bindCheckout($sub);

        trialHook(trialingEvent($sub))->assertOk();
        trialHook(trialingEvent($sub, 'evt_trial_again'))->assertOk();
        trialHook(trialPaidEvent($sub, 'tran_zero', 0, 'evt_zero', 'trialing'))->assertOk();

        $sub->refresh();
        expect($sub->status)->toBe(SubscriptionStatus::Trialing)
            ->and($sub->creem_subscription_id)->toBe('sub_trial')
            ->and($sub->user->plan()->key->value)->toBe('pro')
            ->and(Credits::monthlyAmount($sub->user))->toBe($sub->user->plan()->quota('credits_monthly'));
        $this->analytics->assertCapturedOnce('trial_started', fn ($p) => $p === ['plan' => 'pro', 'period' => 'monthly', 'trial_days' => 7]);
    });

    it('starts the trial from checkout.completed when the other events are lost', function () {
        $sub = trialCheckout();
        trialHook(['id' => 'evt_co', 'eventType' => 'checkout.completed', 'object' => [
            'id' => 'ch_1', 'request_id' => $sub->invoices()->sole()->number,
            'product' => 'prod_pro_monthly', 'customer' => 'cust_trial',
            'subscription' => trialingEvent($sub)['object'],
        ]])->assertOk();
        trialHook(trialingEvent($sub))->assertOk();

        expect($sub->fresh()->status)->toBe(SubscriptionStatus::Trialing);
        $this->analytics->assertCapturedOnce('trial_started');
    });

    it('turns into the paid plan at the first real charge', function () {
        $sub = trialCheckout();
        trialHook(trialPaidEvent($sub, 'tran_zero', 0, 'evt_zero', 'trialing'))->assertOk();
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

        $this->actingAs($user)->postJson('/api/v1/me/subscription/cancel')->assertOk();
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

/**
 * The prod case (user 4, sub 11): a Creem trial started before trials were
 * understood, recorded as Active from its $0 "paid" card check — period
 * set, no trial end, Monthly Credits already granted.
 */
function activeFromZeroPaid(int $amount = 0): Subscription
{
    $user = User::factory()->create();
    $sub = Subscription::factory()->for($user)->create([
        'plan_id' => Plan::query()->where('key', 'pro')->value('id'),
        'billing_period' => 'monthly',
        'status' => SubscriptionStatus::Active,
        'creem_subscription_id' => 'sub_trial', 'creem_customer_id' => 'cust_trial',
        'current_period_start' => now()->subDays(2)->startOfSecond(),
        'current_period_end' => now()->addDays(5)->startOfSecond(),
    ]);
    $sub->invoices()->create([
        'number' => Invoice::nextNumber(), 'amount' => $amount, 'currency' => 'USD',
        'status' => InvoiceStatus::Paid, 'creem_transaction_id' => 'tran_card_check', 'paid_at' => now()->subDays(2),
    ]);

    return $sub;
}

function creemSays(Subscription $sub, string $status): void
{
    freshCreemFake(['test-api.creem.io/v1/subscriptions*' => Http::response([
        'id' => 'sub_trial', 'status' => $status, 'product' => 'prod_pro_monthly', 'customer' => 'cust_trial',
        'current_period_start_date' => $sub->current_period_start->toIso8601String(),
        'current_period_end_date' => $sub->current_period_end->toIso8601String(),
    ])]);
}

describe('billing:reconcile-trials', function () {
    it('starts trials Creem has that main left on Free, once', function () {
        $sub = trialCheckout();
        bindCheckout($sub);
        freshCreemFake(['test-api.creem.io/v1/subscriptions*' => Http::response(trialingEvent($sub)['object'])]);

        $this->artisan('billing:reconcile-trials --dry-run')->expectsOutputToContain('would start')->assertSuccessful();
        expect($sub->fresh()->status)->toBe(SubscriptionStatus::Expired);

        $this->artisan('billing:reconcile-trials')->expectsOutputToContain('1 checked, 1 trial(s) started.')->assertSuccessful();
        expect($sub->fresh()->status)->toBe(SubscriptionStatus::Trialing)
            ->and($sub->user->fresh()->plan()->key->value)->toBe('pro');

        $this->artisan('billing:reconcile-trials')->expectsOutputToContain('0 checked, 0 trial(s) started.')->assertSuccessful();
        $this->analytics->assertCapturedOnce('trial_started');
    });

    it('leaves a subscription Creem does not say is trialing', function () {
        $sub = trialCheckout();
        bindCheckout($sub);
        freshCreemFake(['test-api.creem.io/v1/subscriptions*' => Http::response(['id' => 'sub_trial', 'status' => 'canceled'])]);

        $this->artisan('billing:reconcile-trials')->expectsOutputToContain('left as is')->assertSuccessful();
        expect($sub->fresh()->status)->toBe(SubscriptionStatus::Expired);
    });

    it('turns an Active trial recorded from its $0 card check into Trialing, credits untouched', function () {
        $sub = activeFromZeroPaid();
        $user = $sub->user;
        creemSays($sub, 'trialing');
        $monthly = Credits::monthlyBalance($user);
        $ledger = DB::table('wallet_transactions')->count();

        $this->artisan('billing:reconcile-trials --dry-run')
            ->expectsOutputToContain("{$sub->ulid} (user {$user->id}, active here): trialing in Creem until "
                .$sub->current_period_end->toIso8601String().' — would start')
            ->expectsOutputToContain('1 checked, 0 trial(s) started.')
            ->assertSuccessful();
        expect($sub->fresh()->status)->toBe(SubscriptionStatus::Active);

        $this->artisan('billing:reconcile-trials')->expectsOutputToContain('1 checked, 1 trial(s) started.')->assertSuccessful();

        $fresh = $sub->fresh();
        expect($fresh->status)->toBe(SubscriptionStatus::Trialing)
            ->and($fresh->current_period_start->equalTo($sub->current_period_start))->toBeTrue()
            ->and($fresh->current_period_end->equalTo($sub->current_period_end))->toBeTrue()
            ->and(Credits::monthlyBalance($user))->toBe($monthly)
            ->and(DB::table('wallet_transactions')->count())->toBe($ledger)
            ->and(Trial::eligible($user))->toBeFalse();
        $this->actingAs($user)->getJson('/api/v1/me/subscription')
            ->assertJsonPath('data.subscription.status', 'trialing')
            ->assertJsonPath('data.subscription.trial_ends_at', $sub->current_period_end->toJSON())
            ->assertJsonPath('data.trial.eligible', false)
            ->assertJsonPath('data.plan.key', 'pro');

        // Idempotent: no longer a candidate, and trial_started stays at one.
        $this->artisan('billing:reconcile-trials')->expectsOutputToContain('0 checked, 0 trial(s) started.')->assertSuccessful();
        $this->analytics->assertCapturedOnce('trial_started');
    });

    it('leaves an Active subscription with a real charge alone, without asking Creem', function () {
        $sub = activeFromZeroPaid(999);
        creemSays($sub, 'trialing');

        $this->artisan('billing:reconcile-trials')->expectsOutputToContain('0 checked, 0 trial(s) started.')->assertSuccessful();

        expect($sub->fresh()->status)->toBe(SubscriptionStatus::Active);
        Http::assertNothingSent();
        $this->analytics->assertNotCaptured('trial_started');
    });

    it('leaves an Active $0 subscription alone when Creem says it is active', function () {
        $sub = activeFromZeroPaid();
        creemSays($sub, 'active');

        $this->artisan('billing:reconcile-trials')
            ->expectsOutputToContain('Creem says "active" — left as is')
            ->expectsOutputToContain('1 checked, 0 trial(s) started.')
            ->assertSuccessful();

        expect($sub->fresh()->status)->toBe(SubscriptionStatus::Active);
        $this->analytics->assertNotCaptured('trial_started');
    });

    it('counts the first real charge after such a trial as the first payment', function () {
        $sub = activeFromZeroPaid();
        creemSays($sub, 'trialing');
        $this->artisan('billing:reconcile-trials')->assertSuccessful();
        $this->travel(6)->days();

        trialHook(trialPaidEvent($sub, 'tran_first', 999, 'evt_first'))->assertOk();

        expect($sub->fresh()->status)->toBe(SubscriptionStatus::Active)
            ->and(CreemTrial::charged($sub))->toBeTrue();
        $this->analytics->assertCapturedOnce('subscription_started', fn ($p) => $p['amount_usd'] === 9.99);
    });

    it('converts such a row on Creem\'s subscription.trialing too', function () {
        $sub = activeFromZeroPaid();

        trialHook(trialingEvent($sub, 'evt_late_trialing', [
            'current_period_start_date' => $sub->current_period_start->toIso8601String(),
            'current_period_end_date' => $sub->current_period_end->toIso8601String(),
        ]))->assertOk();

        expect($sub->fresh()->status)->toBe(SubscriptionStatus::Trialing);
    });
});
