<?php

use App\Enums\OrderStatus;
use App\Models\Client;
use App\Models\Handle;
use App\Models\Offer;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Space;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\OrderPaid;
use App\Support\Wallet;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

function fakeOrderSnap(string $token = 'snap-order-1'): void
{
    Http::fake([
        'https://app.sandbox.midtrans.com/snap/v1/transactions' => Http::response(['token' => $token]),
    ]);
}

/** A creator with a handle and one thing for sale. */
function seller(array $offer = []): array
{
    $user = User::factory()->create(['name' => 'Rani']);
    Handle::factory()->for($user)->create(['name' => 'rani']);
    $product = Offer::factory()->for($user)->create($offer);

    return [$user, $product];
}

/**
 * A signed Midtrans body for an order.
 *
 * @return array<string, mixed>
 */
function orderPayload(Order $order, string $status = 'settlement'): array
{
    $gross = number_format($order->amount, 2, '.', '');

    $payload = [
        'order_id' => $order->midtrans_order_id,
        'status_code' => '200',
        'gross_amount' => $gross,
        'transaction_status' => $status,
        'payment_type' => 'qris',
        'fraud_status' => 'accept',
    ];

    $payload['signature_key'] = hash('sha512',
        $payload['order_id'].'200'.$gross.config('services.midtrans.server_key'),
    );

    return $payload;
}

describe('checkout', function () {
    it('snapshots the fee from the seller’s plan at the moment of sale', function () {
        fakeOrderSnap();
        [$user, $offer] = seller(['price' => 149_000]);

        $this->actingAs(Client::factory()->create(), 'client')
            ->postJson('/api/v1/orders', ['offer_id' => $offer->ulid])
            ->assertCreated()
            ->assertJsonPath('data.snap_token', 'snap-order-1');

        $order = Order::query()->firstOrFail();

        // Free plan: 5% of 149.000 = 7.450.
        expect((float) $order->fee_percent)->toBe(5.0)
            ->and($order->fee_amount)->toBe(7_450)
            ->and($order->net_amount)->toBe(141_550)
            ->and($order->fee_amount + $order->net_amount)->toBe($order->amount)
            ->and($order->status)->toBe(OrderStatus::Pending);
    });

    it('charges the paid-plan fee when the seller is on Premium', function () {
        fakeOrderSnap();
        [$user, $offer] = seller(['price' => 149_000]);
        Subscription::factory()->for($user)->create([
            'plan_id' => Plan::query()->where('key', 'premium')->value('id'),
        ]);

        $this->actingAs(Client::factory()->create(), 'client')
            ->postJson('/api/v1/orders', ['offer_id' => $offer->ulid])
            ->assertCreated();

        $order = Order::query()->firstOrFail();

        expect((float) $order->fee_percent)->toBe(2.5)
            ->and($order->fee_amount)->toBe(3_725)
            ->and($order->net_amount)->toBe(145_275);
    });

    it('keeps the old fee after the seller upgrades', function () {
        fakeOrderSnap();
        [$user, $offer] = seller(['price' => 100_000]);

        $this->actingAs(Client::factory()->create(), 'client')
            ->postJson('/api/v1/orders', ['offer_id' => $offer->ulid])->assertCreated();

        Subscription::factory()->for($user)->create([
            'plan_id' => Plan::query()->where('key', 'premium')->value('id'),
        ]);

        // The sale that already happened still cost what it cost.
        expect((float) Order::query()->firstOrFail()->fee_percent)->toBe(5.0);
    });

    it('takes the buyer’s amount for a tip, and refuses a tip with none', function () {
        fakeOrderSnap();
        [, $tip] = seller([]);
        $tip->forceFill(['type' => 'tip', 'price' => null])->save();
        $client = Client::factory()->create();

        $this->actingAs($client, 'client')
            ->postJson('/api/v1/orders', ['offer_id' => $tip->ulid])
            ->assertUnprocessable();

        $this->actingAs($client, 'client')
            ->postJson('/api/v1/orders', ['offer_id' => $tip->ulid, 'amount' => 25_000])
            ->assertCreated()
            ->assertJsonPath('data.amount', 25_000);
    });

    it('ignores an amount the buyer invents for a priced product', function () {
        fakeOrderSnap();
        [, $offer] = seller(['price' => 149_000]);

        $this->actingAs(Client::factory()->create(), 'client')
            ->postJson('/api/v1/orders', ['offer_id' => $offer->ulid, 'amount' => 1_000])
            ->assertCreated()
            ->assertJsonPath('data.amount', 149_000);
    });

    it('refuses a service — those are arranged in conversation', function () {
        [, $service] = seller();
        $service->forceFill(['type' => 'service', 'price_from' => true])->save();

        $this->actingAs(Client::factory()->create(), 'client')
            ->postJson('/api/v1/orders', ['offer_id' => $service->ulid])
            ->assertUnprocessable();
    });

    it('refuses an offer that was switched off, and one that never existed', function () {
        [, $offer] = seller();
        $offer->forceFill(['is_active' => false])->save();
        $client = Client::factory()->create();

        $this->actingAs($client, 'client')
            ->postJson('/api/v1/orders', ['offer_id' => $offer->ulid])
            ->assertUnprocessable();

        $this->actingAs($client, 'client')
            ->postJson('/api/v1/orders', ['offer_id' => 'nope'])
            ->assertUnprocessable();
    });

    it('rejects a buyer who has not signed in', function () {
        [, $offer] = seller();

        $this->postJson('/api/v1/orders', ['offer_id' => $offer->ulid])
            ->assertUnauthorized();
    });
});

describe('payment', function () {
    it('credits the creator the net amount, not the gross', function () {
        Notification::fake();
        [$user, $offer] = seller(['price' => 149_000]);
        $order = Order::factory()->for($user, 'creator')->for($offer)->create([
            'amount' => 149_000,
            'fee_amount' => 7_450,
            'net_amount' => 141_550,
        ]);

        $this->postJson('/api/v1/webhooks/midtrans', orderPayload($order))->assertOk();

        expect($order->refresh()->status)->toBe(OrderStatus::Paid)
            ->and($order->paid_at)->not->toBeNull()
            // The platform fee never enters the creator's balance.
            ->and(Wallet::balance($user))->toBe(141_550);

        Notification::assertSentTo($user, OrderPaid::class);
    });

    it('credits once however many times Midtrans retries', function () {
        Notification::fake();
        [$user] = seller();
        $order = Order::factory()->for($user, 'creator')->create();
        $payload = orderPayload($order);

        $this->postJson('/api/v1/webhooks/midtrans', $payload)->assertOk();
        $this->postJson('/api/v1/webhooks/midtrans', $payload)->assertOk();
        $this->postJson('/api/v1/webhooks/midtrans', $payload)->assertOk();

        expect($user->walletTransactions()->count())->toBe(1)
            ->and(Wallet::balance($user))->toBe($order->net_amount);

        Notification::assertSentToTimes($user, OrderPaid::class, 1);
    });

    it('credits nothing when the payment expires', function () {
        Notification::fake();
        [$user] = seller();
        $order = Order::factory()->for($user, 'creator')->create();

        $this->postJson('/api/v1/webhooks/midtrans', orderPayload($order, 'expire'))->assertOk();

        expect($order->refresh()->status)->toBe(OrderStatus::Expired)
            ->and(Wallet::balance($user))->toBe(0);

        Notification::assertNothingSent();
    });

    it('refuses a forged signature on an order', function () {
        [$user] = seller();
        $order = Order::factory()->for($user, 'creator')->create();

        $payload = orderPayload($order);
        $payload['signature_key'] = str_repeat('f', 128);

        $this->postJson('/api/v1/webhooks/midtrans', $payload)->assertForbidden();

        expect($order->refresh()->status)->toBe(OrderStatus::Pending)
            ->and(Wallet::balance($user))->toBe(0);
    });

    it('does not confuse an order with a subscription invoice', function () {
        Notification::fake();
        [$user] = seller();
        $order = Order::factory()->for($user, 'creator')->create();

        // Both live behind one endpoint; the prefix keeps them apart.
        expect($order->midtrans_order_id)->toStartWith('OR-');

        $this->postJson('/api/v1/webhooks/midtrans', orderPayload($order))->assertOk();

        expect($order->refresh()->status)->toBe(OrderStatus::Paid);
    });
});

describe('the buyer’s own list', function () {
    it('shows what I bought and nobody else’s purchases', function () {
        $me = Client::factory()->create();
        [$user, $offer] = seller();
        Order::factory()->for($me)->for($user, 'creator')->for($offer)->paid()->create();
        Order::factory()->paid()->create(); // someone else's

        $this->actingAs($me, 'client')->getJson('/api/v1/orders/mine')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'paid');
    });

    it('hands a paid buyer the delivery link, and only then', function () {
        $me = Client::factory()->create();
        [$user, $offer] = seller([
            'details' => ['source_ref' => 'creatif.space/rani/noel-preset-files'],
        ]);
        Order::factory()->for($me)->for($user, 'creator')->for($offer)->paid()->create();
        Order::factory()->for($me)->for($user, 'creator')->for($offer)->create(); // pending

        $rows = $this->actingAs($me, 'client')->getJson('/api/v1/orders/mine')
            ->assertOk()->json('data');

        $paid = collect($rows)->firstWhere('status', 'paid');
        $pending = collect($rows)->firstWhere('status', 'pending');
        expect($paid['delivery_url'])->toBe('https://creatif.space/rani/noel-preset-files')
            ->and($pending)->not->toHaveKey('delivery_url');
    });
});

describe('delivery to the buyer', function () {
    it('emails the buyer their link when the webhook settles', function () {
        Notification::fake();
        $me = Client::factory()->create();
        [$user, $offer] = seller([
            'details' => ['source_ref' => 'https://drive.google.com/file/d/abc'],
        ]);
        $order = Order::factory()->for($me)->for($user, 'creator')->for($offer)->create();

        $this->postJson('/api/v1/webhooks/midtrans', orderPayload($order))->assertOk();

        Notification::assertSentToTimes($me, \App\Notifications\OrderDelivered::class, 1);
        expect($order->refresh()->deliveryUrl())
            ->toBe('https://drive.google.com/file/d/abc');
    });

    it('turns junk source_ref into no link rather than a broken one', function () {
        [$user, $offer] = seller(['details' => ['source_ref' => 'not a url at all']]);
        $order = Order::factory()->for($user, 'creator')->for($offer)->paid()->create();

        expect($order->deliveryUrl())->toBeNull();
    });
});

describe('offers on the public pages', function () {
    it('shows a Space’s offers only while it is selling', function () {
        [$user, $offer] = seller(['price' => 149_000]);
        $space = Space::factory()->for($user)->published()->create([
            'slug' => 'winter-noel',
            'selling_enabled' => true,
        ]);
        $offer->forceFill(['space_id' => $space->id])->save();

        $this->getJson('/api/v1/profiles/rani/spaces/winter-noel')
            ->assertOk()
            ->assertJsonCount(1, 'data.offers')
            ->assertJsonPath('data.offers.0.price', 149_000)
            ->assertJsonPath('data.offers.0.buyable', true);

        $space->forceFill(['selling_enabled' => false])->save();

        $this->getJson('/api/v1/profiles/rani/spaces/winter-noel')
            ->assertOk()
            ->assertJsonCount(0, 'data.offers');
    });

    it('lists profile offers, and hides the ones kept off the profile', function () {
        [$user] = seller(['title' => 'On the profile']);
        Offer::factory()->for($user)->create([
            'title' => 'Space only',
            'show_on_profile' => false,
        ]);

        $this->getJson('/api/v1/profiles/rani')
            ->assertOk()
            ->assertJsonCount(1, 'data.offers')
            ->assertJsonPath('data.offers.0.title', 'On the profile');
    });
});
