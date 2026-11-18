<?php

use App\Enums\InvoiceStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    // The first checkout is ch_test; any later one gets its own id, as Creem does.
    $calls = 0;
    Http::fake(['test-api.creem.io/*' => function () use (&$calls) {
        return Http::response([
            'id' => $calls++ === 0 ? 'ch_test' : 'ch_'.$calls, 'checkout_url' => 'https://checkout.creem.io/test',
            'customer_portal_link' => 'https://creem.io/my-orders/test',
        ]);
    }]);
});

it('sells only Pro and Max at the approved USD prices; lists Free, and Enterprise as contact sales', function () {
    $rows = collect($this->getJson('/api/v1/plans')->assertOk()->assertJsonCount(4, 'data')->json('data'))->keyBy('key');
    expect($rows->keys()->all())->toBe(['free', 'pro', 'max', 'enterprise'])
        ->and($rows->where('checkout', true)->keys()->all())->toBe(['pro', 'max'])
        ->and($rows['enterprise']['contact_sales'])->toBeTrue()
        ->and($rows['enterprise']['price_monthly'])->toBeNull()
        ->and($rows['pro']['price_monthly'])->toBe(999)
        ->and($rows['pro']['price_yearly'])->toBe(9990)
        ->and($rows['max']['price_monthly'])->toBe(1499)
        ->and($rows['max']['price_yearly'])->toBe(14990)
        ->and($rows['free']['quotas']['spaces_total'])->toBe(15)
        ->and($rows['free']['quotas']['social_posts_monthly'])->toBe(10)
        ->and($rows['pro']['quotas']['social_posts_monthly'])->toBe(150)
        ->and($rows['max']['quotas']['social_posts_monthly'])->toBe(500)
        ->and($rows['free']['quotas']['credits_monthly'])->toBe(3000)
        ->and($rows['pro']['quotas']['credits_monthly'])->toBe(45000)
        ->and($rows['max']['quotas']['credits_monthly'])->toBe(150000);
});

it('reports Free without a subscription', function () {
    $this->actingAs(User::factory()->create())->getJson('/api/v1/me/subscription')
        ->assertOk()->assertJsonPath('data.plan.key', 'free')->assertJsonPath('data.subscription', null);
});

it('creates pending billing state and returns a hosted checkout, never entitlement', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->postJson('/api/v1/me/subscription/checkout', ['plan' => 'pro', 'period' => 'monthly'])
        ->assertCreated()->assertJsonPath('data.checkout_url', 'https://checkout.creem.io/test')
        ->assertJsonPath('data.amount', 999);
    $invoice = Invoice::query()->sole();
    expect($invoice->status)->toBe(InvoiceStatus::Pending)
        ->and($invoice->creem_checkout_id)->toBe('ch_test')
        ->and($user->fresh()->plan()->key->value)->toBe('free');
    Http::assertSent(fn ($r) => $r->url() === 'https://test-api.creem.io/v1/checkouts'
        && $r['product_id'] === 'prod_pro_monthly' && $r['request_id'] === $invoice->number
        && $r->hasHeader('x-api-key', 'creem_test_fixture'));
});

it('reuses one checkout for a repeated click and replaces it for another choice', function () {
    $user = User::factory()->create();
    $go = fn (string $plan) => $this->actingAs($user)
        ->postJson('/api/v1/me/subscription/checkout', ['plan' => $plan, 'period' => 'monthly'])->assertCreated();
    $go('pro');
    $go('pro');
    expect(Invoice::query()->where('status', InvoiceStatus::Pending)->count())->toBe(1);
    Http::assertSentCount(1);
    $go('max');
    expect(Invoice::query()->where('status', InvoiceStatus::Pending)->count())->toBe(1)
        ->and(Invoice::query()->where('status', InvoiceStatus::Expired)->count())->toBe(1);
});

it('never opens a second subscription for a choice whose checkout is still being made (checkout dobel)', function () {
    $user = User::factory()->create();
    // What a racing first request leaves before its Creem call returns:
    // an open unpaid choice with no hosted page yet.
    $subscription = $user->subscriptions()->create([
        'plan_id' => \App\Models\Plan::query()->where('key', 'pro')->sole()->id,
        'billing_period' => 'monthly', 'seats' => 1, 'status' => SubscriptionStatus::Expired,
    ]);
    $invoice = $subscription->invoices()->create([
        'number' => Invoice::nextNumber(), 'amount' => 999, 'currency' => 'USD',
        'status' => InvoiceStatus::Pending, 'due_at' => now()->addHours(Invoice::DUE_HOURS),
    ]);

    $this->actingAs($user)->postJson('/api/v1/me/subscription/checkout', ['plan' => 'pro', 'period' => 'monthly'])
        ->assertCreated()->assertJsonPath('data.checkout_url', 'https://checkout.creem.io/test');

    expect($user->subscriptions()->count())->toBe(1)
        ->and(Invoice::query()->count())->toBe(1)
        ->and($invoice->fresh()->creem_checkout_id)->toBe('ch_test');
    Http::assertSentCount(1);
});

it('leaves nothing behind when Creem refuses the checkout', function () {
    // A fresh fake: the beforeEach stub would otherwise answer first.
    Http::swap(new \Illuminate\Http\Client\Factory);
    Http::preventStrayRequests();
    Http::fake(['test-api.creem.io/*' => Http::response(['message' => 'down'], 503)]);
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/api/v1/me/subscription/checkout', ['plan' => 'pro', 'period' => 'monthly'])
        ->assertServerError();

    expect($user->subscriptions()->count())->toBe(0)
        ->and(Invoice::query()->count())->toBe(0);
});

it('charges the selected plan and period', function (string $plan, string $period, int $amount) {
    $this->actingAs(User::factory()->create())->postJson('/api/v1/me/subscription/checkout', compact('plan', 'period'))
        ->assertCreated()->assertJsonPath('data.amount', $amount);
})->with([['pro', 'yearly', 9990], ['max', 'monthly', 1499], ['max', 'yearly', 14990]]);

it('refuses Team, Premium and Free checkout', function (string $plan) {
    $this->actingAs(User::factory()->create())->postJson('/api/v1/me/subscription/checkout', ['plan' => $plan, 'period' => 'monthly'])
        ->assertUnprocessable()->assertJsonValidationErrors('plan');
    Http::assertNothingSent();
})->with(['free', 'premium', 'team']);

it('upgrades the existing subscription instead of creating another checkout', function () {
    $sub = Subscription::factory()->create(['creem_subscription_id' => 'sub_test']);
    $this->actingAs($sub->user)->postJson('/api/v1/me/subscription/checkout', ['plan' => 'max', 'period' => 'yearly'])
        ->assertStatus(202)->assertJsonPath('data.checkout_url', null);
    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/subscriptions/sub_test/upgrade')
        && $r['product_id'] === 'prod_max_yearly');
    expect($sub->fresh()->plan->key->value)->toBe('pro');
});

it('refuses an identical plan and period', function () {
    $sub = Subscription::factory()->create();
    $this->actingAs($sub->user)->postJson('/api/v1/me/subscription/checkout', ['plan' => 'pro', 'period' => 'monthly'])
        ->assertUnprocessable();
});

it('schedules cancellation through Creem and keeps the paid period', function () {
    $sub = Subscription::factory()->create(['creem_subscription_id' => 'sub_test']);
    $this->actingAs($sub->user)->postJson('/api/v1/me/subscription/cancel')
        ->assertOk()->assertJsonPath('data.ends_at_period_end', true);
    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/subscriptions/sub_test/cancel')
        && $r['mode'] === 'scheduled' && $r['onExecute'] === 'cancel');
    expect($sub->fresh()->status)->toBe(SubscriptionStatus::Active);
});

it('returns the owning customer portal only', function () {
    $sub = Subscription::factory()->create(['creem_customer_id' => 'cust_test']);
    $this->actingAs($sub->user)->postJson('/api/v1/me/subscription/portal')
        ->assertOk()->assertJsonPath('data.url', 'https://creem.io/my-orders/test');
    Http::assertSent(fn ($r) => $r['customer_id'] === 'cust_test');
});

it('keeps invoices private', function () {
    $mine = Subscription::factory()->create();
    Invoice::factory()->for($mine)->paid()->create();
    Invoice::factory()->paid()->create();
    $this->actingAs($mine->user)->getJson('/api/v1/me/invoices')->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.currency', 'USD');
});

it('requires authentication for billing', function () {
    $this->getJson('/api/v1/me/subscription')->assertUnauthorized();
    $this->postJson('/api/v1/me/subscription/checkout')->assertUnauthorized();
});
