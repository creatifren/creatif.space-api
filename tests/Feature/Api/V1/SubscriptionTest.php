<?php

use App\Enums\InvoiceStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Space;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\Http;

function fakeSnap(string $token = 'snap-token-1'): void
{
    Http::fake([
        'https://app.sandbox.midtrans.com/snap/v1/transactions' => Http::response([
            'token' => $token,
            'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v2/vtweb/'.$token,
        ]),
    ]);
}

describe('plans', function () {
    it('serves the pricing table with unlimited as null', function () {
        $response = $this->getJson('/api/v1/plans')
            ->assertOk()
            ->assertJsonCount(3, 'data');

        $free = collect($response->json('data'))->firstWhere('key', 'free');
        $premium = collect($response->json('data'))->firstWhere('key', 'premium');

        expect($free['price_monthly'])->toBe(0)
            ->and($free['quotas']['spaces_total'])->toBe(10)
            ->and($premium['price_monthly'])->toBe(94_000)
            ->and($premium['price_yearly'])->toBe(840_000)
            // No limit is null, never zero — zero would read as "none".
            ->and($premium['quotas']['spaces_total'])->toBeNull();
    });

    it('is open to anyone — pricing is a public page', function () {
        $this->getJson('/api/v1/plans')->assertOk();
    });

    it('hides a retired plan', function () {
        Plan::query()->where('key', 'team')->update(['is_active' => false]);

        $this->getJson('/api/v1/plans')->assertOk()->assertJsonCount(2, 'data');
    });
});

describe('the current plan', function () {
    it('reports Free when nothing has been bought', function () {
        $this->actingAs(User::factory()->create())
            ->getJson('/api/v1/me/subscription')
            ->assertOk()
            ->assertJsonPath('data.plan.key', 'free')
            ->assertJsonPath('data.subscription', null)
            ->assertJsonPath('data.quota.total_limit', 10);
    });

    it('reports the paid plan, with no ceilings', function () {
        $user = User::factory()->create();
        Subscription::factory()->for($user)->create([
            'plan_id' => Plan::query()->where('key', 'premium')->value('id'),
        ]);

        $this->actingAs($user)->getJson('/api/v1/me/subscription')
            ->assertOk()
            ->assertJsonPath('data.plan.key', 'premium')
            ->assertJsonPath('data.quota.total_limit', null);
    });

    it('rejects guests', function () {
        $this->getJson('/api/v1/me/subscription')->assertUnauthorized();
        $this->postJson('/api/v1/me/subscription/checkout')->assertUnauthorized();
    });
});

describe('checkout', function () {
    it('creates an unpaid subscription, an invoice and a Snap token', function () {
        fakeSnap();
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson('/api/v1/me/subscription/checkout', [
                'plan' => 'premium',
                'period' => 'monthly',
            ])
            ->assertCreated()
            ->assertJsonPath('data.snap_token', 'snap-token-1')
            ->assertJsonPath('data.amount', 94_000);

        $subscription = $user->subscriptions()->firstOrFail();
        $invoice = Invoice::query()->firstOrFail();

        expect($invoice->status)->toBe(InvoiceStatus::Pending)
            ->and($invoice->number)->toStartWith('INV-'.now()->year.'-')
            ->and($invoice->midtrans_snap_token)->toBe('snap-token-1')
            ->and($response->json('data.order_id'))->toBe('CS-'.$subscription->ulid)
            // Not entitled until the money lands — the webhook does that.
            ->and($subscription->status)->toBe(SubscriptionStatus::Expired)
            ->and($user->fresh()->plan()->key->value)->toBe('free');
    });

    it('charges the yearly price for a yearly period', function () {
        fakeSnap();

        $this->actingAs(User::factory()->create())
            ->postJson('/api/v1/me/subscription/checkout', [
                'plan' => 'premium',
                'period' => 'yearly',
            ])
            ->assertCreated()
            ->assertJsonPath('data.amount', 840_000);
    });

    it('bills Team for the seats beyond the three it includes', function () {
        fakeSnap();

        // 270.000 + 2 × 44.000
        $this->actingAs(User::factory()->create())
            ->postJson('/api/v1/me/subscription/checkout', [
                'plan' => 'team',
                'period' => 'monthly',
                'seats' => 5,
            ])
            ->assertCreated()
            ->assertJsonPath('data.amount', 358_000);
    });

    it('refuses to sell the plan you are already on', function () {
        $user = User::factory()->create();
        Subscription::factory()->for($user)->create([
            'plan_id' => Plan::query()->where('key', 'premium')->value('id'),
        ]);

        $this->actingAs($user)
            ->postJson('/api/v1/me/subscription/checkout', [
                'plan' => 'premium',
                'period' => 'monthly',
            ])
            ->assertUnprocessable();
    });

    it('refuses a plan that is not for sale', function () {
        $this->actingAs(User::factory()->create())
            ->postJson('/api/v1/me/subscription/checkout', [
                'plan' => 'free',
                'period' => 'monthly',
            ])
            ->assertUnprocessable();
    });

    it('numbers invoices in sequence', function () {
        fakeSnap();

        foreach (['premium', 'team'] as $plan) {
            $this->actingAs(User::factory()->create())
                ->postJson('/api/v1/me/subscription/checkout', [
                    'plan' => $plan,
                    'period' => 'monthly',
                ])->assertCreated();
        }

        expect(Invoice::query()->orderBy('id')->pluck('number')->all())
            ->toBe(['INV-'.now()->year.'-000001', 'INV-'.now()->year.'-000002']);
    });
});

describe('cancelling', function () {
    it('keeps the plan running until the period already paid for ends', function () {
        $user = User::factory()->create();
        $subscription = Subscription::factory()->for($user)->create([
            'plan_id' => Plan::query()->where('key', 'premium')->value('id'),
            'current_period_end' => now()->addWeeks(2),
        ]);

        $this->actingAs($user)->postJson('/api/v1/me/subscription/cancel')
            ->assertOk()
            ->assertJsonPath('data.ends_at_period_end', true);

        expect($subscription->refresh()->status)->toBe(SubscriptionStatus::Active)
            ->and($subscription->cancelled_at)->not->toBeNull()
            // Still Premium today — that is what "cancel any time" means.
            ->and($user->fresh()->plan()->key->value)->toBe('premium');
    });

    it('refuses when there is nothing to cancel', function () {
        $this->actingAs(User::factory()->create())
            ->postJson('/api/v1/me/subscription/cancel')
            ->assertUnprocessable();
    });
});

describe('billing history', function () {
    it('lists my invoices and nobody else’s', function () {
        $user = User::factory()->create();
        $mine = Subscription::factory()->for($user)->create();
        Invoice::factory()->for($mine)->paid()->create(['number' => 'INV-2026-000001']);
        Invoice::factory()->create(['number' => 'INV-2026-000002']); // someone else's

        $this->actingAs($user)->getJson('/api/v1/me/invoices')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.number', 'INV-2026-000001')
            ->assertJsonPath('data.0.status', 'paid');
    });
});

describe('quota follows the plan', function () {
    it('stops a Free user at ten Spaces and lets Premium past it', function () {
        $user = User::factory()->create();
        Space::factory()->count(10)->for($user)->create();

        $this->actingAs($user)->postJson('/api/v1/spaces', [
            'title' => 'One too many',
            'purpose' => 'portfolio',
            'view_mode' => 'grid',
        ])->assertUnprocessable();

        Subscription::factory()->for($user)->create([
            'plan_id' => Plan::query()->where('key', 'premium')->value('id'),
        ]);

        $this->actingAs($user)->postJson('/api/v1/spaces', [
            'title' => 'Room at last',
            'purpose' => 'portfolio',
            'view_mode' => 'grid',
        ])->assertCreated();
    });

    it('keeps every Space when a plan lapses — nothing is deleted', function () {
        $user = User::factory()->create();
        $subscription = Subscription::factory()->for($user)->create([
            'plan_id' => Plan::query()->where('key', 'premium')->value('id'),
        ]);
        Space::factory()->count(14)->for($user)->create();

        $subscription->forceFill(['status' => SubscriptionStatus::Expired])->save();

        expect($user->fresh()->plan()->key->value)->toBe('free')
            ->and($user->spaces()->count())->toBe(14);

        // Only making a new one stops.
        $this->actingAs($user)->postJson('/api/v1/spaces', [
            'title' => 'Not now',
            'purpose' => 'portfolio',
            'view_mode' => 'grid',
        ])->assertUnprocessable();
    });
});
