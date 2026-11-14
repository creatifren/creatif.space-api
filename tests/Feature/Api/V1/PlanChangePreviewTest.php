<?php

use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Credits;
use Illuminate\Support\Facades\Http;

/*
 * Bug b202611140813 (CEO q202611140950-upgradeconfirm): "Switch to Max"
 * charged the card on the click. The screen now reads this preview into a
 * confirm sheet first; checkout runs only on Confirm. The preview itself
 * never reaches Creem.
 */

beforeEach(function () {
    Http::preventStrayRequests();
    Http::fake();
    $this->freezeTime();
});

function subWithHalfLeft(array $attributes = []): Subscription
{
    return Subscription::factory()->create([
        'creem_subscription_id' => 'sub_test',
        'current_period_start' => now()->subDays(15),
        'current_period_end' => now()->addDays(15),
        ...$attributes,
    ]);
}

function previewFor(User $user, string $plan, string $period)
{
    return test()->actingAs($user)->getJson("/api/v1/me/subscription/change-preview?plan={$plan}&period={$period}");
}

it('prices Pro → Max monthly halfway through the month: half the difference now, Max from the same renewal date', function () {
    $sub = subWithHalfLeft();

    previewFor($sub->user, 'max', 'monthly')->assertOk()
        ->assertJsonPath('data.plan.key', 'max')
        ->assertJsonPath('data.price', 1499)
        ->assertJsonPath('data.current.plan.key', 'pro')
        ->assertJsonPath('data.current.paid', 999)
        ->assertJsonPath('data.charge_now', 250)
        ->assertJsonPath('data.credit', 0)
        ->assertJsonPath('data.renews_at', $sub->current_period_end->toIso8601String())
        ->assertJsonPath('data.renews_amount', 1499)
        ->assertJsonPath('data.period_changes', false)
        ->assertJsonPath('data.estimated', true);

    Http::assertNothingSent();
    expect($sub->fresh()->plan->key->value)->toBe('pro');
});

it('counts what this period was actually paid (a discount stays counted)', function () {
    $sub = subWithHalfLeft();
    Invoice::factory()->create(['subscription_id' => $sub->id, 'amount' => 799, 'status' => 'paid', 'paid_at' => now()->subDays(15)]);

    previewFor($sub->user, 'max', 'monthly')->assertOk()
        ->assertJsonPath('data.current.paid', 799)
        ->assertJsonPath('data.charge_now', 350);
});

it('charges nothing for a move down: the difference is a credit', function () {
    $sub = subWithHalfLeft(['plan_id' => Plan::query()->where('key', 'max')->value('id')]);

    previewFor($sub->user, 'pro', 'monthly')->assertOk()
        ->assertJsonPath('data.charge_now', 0)
        ->assertJsonPath('data.credit', 250);
});

it('prices monthly → yearly as the new year less what is left of the month, renewing a year from now', function () {
    $sub = subWithHalfLeft();

    previewFor($sub->user, 'pro', 'yearly')->assertOk()
        ->assertJsonPath('data.price', 9990)
        ->assertJsonPath('data.charge_now', 9990 - 500)
        ->assertJsonPath('data.period_changes', true)
        ->assertJsonPath('data.renews_at', now()->toImmutable()->addYearNoOverflow()->toIso8601String())
        ->assertJsonPath('data.renews_amount', 9990);
});

it('refuses what checkout refuses: no paid plan, the same plan, a plan not changeable online, a plan not sold', function () {
    previewFor(User::factory()->create(), 'max', 'monthly')->assertUnprocessable()->assertJsonValidationErrors('plan');

    $same = subWithHalfLeft();
    previewFor($same->user, 'pro', 'monthly')->assertUnprocessable()->assertJsonValidationErrors('plan');

    $offline = subWithHalfLeft(['creem_subscription_id' => null]);
    previewFor($offline->user, 'max', 'monthly')->assertUnprocessable()->assertJsonValidationErrors('plan');

    previewFor($same->user, 'team', 'monthly')->assertUnprocessable();
    Http::assertNothingSent();
});

it('is owner-only and signed-in only', function () {
    $this->getJson('/api/v1/me/subscription/change-preview?plan=max&period=monthly')->assertUnauthorized();
});

it('reports Credits "Resets" and the plan\'s "Renews" as one date on a monthly plan', function () {
    $sub = subWithHalfLeft(['current_period_end' => now()->addDays(15)->setTime(21, 30)]);

    $data = $this->actingAs($sub->user)->getJson('/api/v1/me/subscription')->assertOk()->json('data');
    expect($data['monthly_resets_at'])->toBe($sub->fresh()->current_period_end->toIso8601String())
        ->and(Credits::cycleEnds($sub->user)->equalTo($sub->current_period_end))->toBeTrue();

    $credits = $this->actingAs($sub->user)->getJson('/api/v1/credits')->assertOk()->json('data');
    expect($credits['monthly_resets_at'])->toBe($data['monthly_resets_at']);
});

it('keeps the monthly cycle on a yearly plan (Credits reset every month, the plan renews once a year)', function () {
    $sub = subWithHalfLeft(['billing_period' => 'yearly', 'current_period_start' => now()->subDays(40), 'current_period_end' => now()->addDays(325)]);

    expect(Credits::cycleEnds($sub->user)->lessThan(now()->addDays(32)))->toBeTrue();
});
