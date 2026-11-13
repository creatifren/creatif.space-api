<?php

use App\Enums\InvoiceStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;

/*
 * P1 billing (13 Nov): a Pro owner whose plan is set to cancel chose Max;
 * Creem answered 400 subscription_not_active and checkout returned 500.
 * Now: resume at Creem, then change plan; if Creem still refuses, a 409
 * with code subscription_not_active the app can show, never a 500.
 */

const SNA_NOT_ACTIVE = ['error' => 'Bad Request', 'code' => 'subscription_not_active', 'message' => 'Subscription is not active'];

/** Creem by path: each value is a list of [status, body] answered in turn. */
function snaCreem(array $routes): void
{
    Http::preventStrayRequests();
    $seen = [];
    Http::fake(function (HttpRequest $r) use ($routes, &$seen) {
        foreach ($routes as $suffix => $answers) {
            if (str_ends_with(parse_url($r->url(), PHP_URL_PATH), $suffix)) {
                $i = $seen[$suffix] = ($seen[$suffix] ?? -1) + 1;
                [$status, $body] = $answers[min($i, count($answers) - 1)];

                return Http::response($body, $status);
            }
        }

        return Http::response(['id' => 'ch_test', 'checkout_url' => 'https://checkout.creem.io/test'], 200);
    });
}

function snaEndingPro(): Subscription
{
    return Subscription::factory()->create([
        'creem_subscription_id' => 'sub_end',
        'cancelled_at' => now()->subDay(),
        'current_period_end' => now()->addDays(20),
    ]);
}

function snaSent(): array
{
    return Http::recorded()->map(fn ($pair) => parse_url($pair[0]->url(), PHP_URL_PATH))->all();
}

it('resumes a plan set to end, then changes it to Max', function () {
    $sub = snaEndingPro();
    snaCreem(['/resume' => [[200, ['id' => 'sub_end', 'status' => 'active']]], '/upgrade' => [[200, ['id' => 'sub_end']]]]);

    $this->actingAs($sub->user)->postJson('/api/v1/me/subscription/checkout', ['plan' => 'max', 'period' => 'monthly'])
        ->assertStatus(202)->assertJsonPath('data.changing_to.plan', 'max');

    expect(snaSent())->toBe(['/v1/subscriptions/sub_end/resume', '/v1/subscriptions/sub_end/upgrade'])
        ->and($sub->fresh()->cancelled_at)->toBeNull();
});

it('Creem 400 subscription_not_active on upgrade (not yet marked ending here): resumes and retries', function () {
    $sub = Subscription::factory()->create(['creem_subscription_id' => 'sub_end']);
    snaCreem(['/upgrade' => [[400, SNA_NOT_ACTIVE], [200, ['id' => 'sub_end']]], '/resume' => [[200, ['id' => 'sub_end']]]]);

    $this->actingAs($sub->user)->postJson('/api/v1/me/subscription/checkout', ['plan' => 'max', 'period' => 'monthly'])
        ->assertStatus(202);

    expect(snaSent())->toBe(['/v1/subscriptions/sub_end/upgrade', '/v1/subscriptions/sub_end/resume', '/v1/subscriptions/sub_end/upgrade']);
});

it('never 500: Creem keeps saying subscription_not_active → 409 with the code and a message, the resume undone', function () {
    $sub = snaEndingPro();
    snaCreem([
        '/resume' => [[200, ['id' => 'sub_end']]],
        '/upgrade' => [[400, SNA_NOT_ACTIVE]],
        '/cancel' => [[200, ['id' => 'sub_end']]],
    ]);

    $res = $this->actingAs($sub->user)->postJson('/api/v1/me/subscription/checkout', ['plan' => 'max', 'period' => 'monthly'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'subscription_not_active');
    expect($res->json('message'))->toContain('Pro plan is set to end on')
        ->and($res->json('ends_at'))->not->toBeNull()
        ->and(snaSent())->toBe(['/v1/subscriptions/sub_end/resume', '/v1/subscriptions/sub_end/upgrade', '/v1/subscriptions/sub_end/cancel'])
        ->and($sub->fresh()->cancelled_at)->not->toBeNull()
        ->and(Subscription::query()->count())->toBe(1)
        ->and($sub->user->fresh()->plan()->key->value)->toBe('pro');
});

it('never 500: resume itself refused (400 subscription_not_active) → 409, nothing to undo', function () {
    $sub = snaEndingPro();
    snaCreem(['/resume' => [[400, SNA_NOT_ACTIVE]]]);

    $this->actingAs($sub->user)->postJson('/api/v1/me/subscription/checkout', ['plan' => 'max', 'period' => 'yearly'])
        ->assertStatus(409)->assertJsonPath('code', 'subscription_not_active');
    expect(collect(snaSent())->contains(fn ($p) => str_ends_with($p, '/cancel')))->toBeFalse();
});

it('/me/subscription never shows the placeholder an abandoned checkout leaves', function () {
    $user = User::factory()->create();
    snaCreem([]);
    $this->actingAs($user)->postJson('/api/v1/me/subscription/checkout', ['plan' => 'pro', 'period' => 'monthly'])->assertCreated();
    Invoice::query()->update(['status' => InvoiceStatus::Expired, 'due_at' => now()->subDay()]);
    expect(Subscription::query()->sole()->status)->toBe(SubscriptionStatus::Expired);

    $this->getJson('/api/v1/me/subscription')->assertOk()
        ->assertJsonPath('data.plan.key', 'free')
        ->assertJsonPath('data.subscription', null);

    // A real plan next to an abandoned checkout: the real one, never "expired".
    $paid = Subscription::factory()->for($user)->create();
    Subscription::factory()->for($user)->expired()->create();
    $this->getJson('/api/v1/me/subscription')->assertOk()
        ->assertJsonPath('data.subscription.id', $paid->ulid)
        ->assertJsonPath('data.subscription.status', 'active');
});
