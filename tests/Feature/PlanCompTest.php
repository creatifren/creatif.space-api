<?php

use App\Enums\InvoiceStatus;
use App\Jobs\ExpireSubscriptions;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Analytics;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

/*
 * plan:comp — staff give an existing account a plan (Pro or Max) until a
 * date, without Creem: the account's Creem trial/subscription is cancelled
 * at once (no charge), its open invoices are voided, and no later Creem
 * webhook can drop it to Free while the comp runs. --revoke ends it.
 */

beforeEach(function () {
    config(['services.creem.api_key' => 'creem_test_key', 'services.creem.test_mode' => true]);
    Notification::fake();
    Analytics::fake();
    Http::preventStrayRequests();
});

/** Creem answers every cancel with $status. */
function pcCreem(int $status = 200): void
{
    Http::fake(fn (HttpRequest $r) => Http::response(
        $status < 300 ? ['id' => 'sub_trial', 'status' => 'canceled'] : ['error' => 'Bad Request', 'message' => 'nope'],
        $status,
    ));
}

/** The owner on a Creem Pro trial, with an open (pending) invoice. */
function pcTrialOwner(): array
{
    $user = User::factory()->create(['email' => 'owner@example.test']);
    $trial = Subscription::factory()->for($user)->create([
        'creem_subscription_id' => 'sub_trial', 'creem_customer_id' => 'cust_1',
        'status' => 'trialing', 'current_period_end' => now()->addHours(6),
    ]);
    $invoice = $trial->invoices()->create(['number' => 'INV-2026-000006', 'amount' => 999, 'currency' => 'USD', 'status' => InvoiceStatus::Pending, 'due_at' => now()->addDay()]);

    return [$user, $trial, $invoice];
}

function pcRun(array $args): array
{
    $code = Artisan::call('plan:comp', ['email' => 'owner@example.test', ...$args]);

    return [$code, trim(Artisan::output())];
}

it('comps Pro until the date, cancels the Creem trial at once (no charge), voids the open invoice', function () {
    [$user, $trial, $invoice] = pcTrialOwner();
    pcCreem();

    [$code, $out] = pcRun(['--plan' => 'pro', '--until' => '2027-11-14']);

    expect($code)->toBe(0);
    $active = $user->fresh()->activeSubscription();
    expect($user->fresh()->plan()->key->value)->toBe('pro')
        ->and($active->plan_source)->toBe(Subscription::SOURCE_STAFF_COMP)
        ->and($active->creem_subscription_id)->toBeNull()
        ->and($active->current_period_end->toDateString())->toBe('2027-11-14')
        ->and($user->fresh()->plan()->quotas['credits_monthly'])->toBe(45000)
        ->and($user->fresh()->plan()->quotas['social_posts_monthly'])->toBe(150);
    expect($trial->fresh()->status->value)->toBe('expired')
        ->and($trial->fresh()->cancelled_at)->not->toBeNull()
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Expired);

    Http::assertSent(fn (HttpRequest $r) => $r->method() === 'POST'
        && str_ends_with($r->url(), '/v1/subscriptions/sub_trial/cancel')
        && $r['mode'] === 'immediate');
    Http::assertSentCount(1);

    expect($out)->toBe('owner@example.test: Pro (staff comp) until 2027-11-14 · Creem: cancelled now, no charge (sub_trial) · invoices voided: INV-2026-000006')
        ->not->toContain('creem_test_key');
});

it('accepts max', function () {
    pcTrialOwner();
    pcCreem();

    pcRun(['--plan' => 'max', '--until' => now()->addMonths(6)->toDateString()]);

    expect(User::query()->where('email', 'owner@example.test')->sole()->plan()->key->value)->toBe('max');
});

it('a later Creem webhook (trial ended, canceled, expired) cannot drop a comped account to Free', function (string $type) {
    [$user] = pcTrialOwner();
    pcCreem();
    pcRun(['--plan' => 'pro', '--until' => '2027-11-14']);

    $event = ['id' => 'evt_'.$type, 'eventType' => $type, 'object' => ['id' => 'sub_trial', 'status' => 'expired']];
    $body = json_encode($event, JSON_THROW_ON_ERROR);
    $this->call('POST', '/api/v1/webhooks/creem', [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        'HTTP_CREEM_SIGNATURE' => hash_hmac('sha256', $body, (string) config('services.creem.webhook_secret')),
    ], $body)->assertOk();

    expect($user->fresh()->plan()->key->value)->toBe('pro')
        ->and($user->fresh()->activeSubscription()->plan_source)->toBe(Subscription::SOURCE_STAFF_COMP);
})->with(['subscription.expired', 'subscription.canceled', 'subscription.unpaid', 'subscription.past_due']);

it('is idempotent: a rerun changes nothing and calls Creem no more', function () {
    [$user] = pcTrialOwner();
    pcCreem();
    pcRun(['--plan' => 'pro', '--until' => '2027-11-14']);
    $rows = Subscription::query()->where('user_id', $user->id)->get()->map->only(['id', 'status', 'plan_id', 'current_period_end'])->all();

    [$code, $out] = pcRun(['--plan' => 'pro', '--until' => '2027-11-14']);

    expect($code)->toBe(0)
        ->and(Subscription::query()->where('user_id', $user->id)->get()->map->only(['id', 'status', 'plan_id', 'current_period_end'])->all())->toEqual($rows)
        ->and($out)->toContain('Creem: nothing to cancel')
        ->and($out)->toContain('invoices voided: none');
    Http::assertSentCount(1);
});

it('a rerun with another date or plan moves the same comp, it does not add one', function () {
    [$user] = pcTrialOwner();
    pcCreem();
    pcRun(['--plan' => 'pro', '--until' => '2027-11-14']);

    pcRun(['--plan' => 'max', '--until' => '2027-12-31']);

    $comps = Subscription::query()->where('user_id', $user->id)->where('plan_source', Subscription::SOURCE_STAFF_COMP)->get();
    expect($comps)->toHaveCount(1)
        ->and($user->fresh()->plan()->key->value)->toBe('max')
        ->and($comps->first()->current_period_end->toDateString())->toBe('2027-12-31');
});

it('a Creem failure changes nothing in our database and says so', function () {
    [$user, $trial, $invoice] = pcTrialOwner();
    pcCreem(500);

    [$code, $out] = pcRun(['--plan' => 'pro', '--until' => '2027-11-14']);

    expect($code)->toBe(1)
        ->and($out)->toContain('Creem refused to cancel sub_trial')
        ->and($out)->toContain('nothing was changed')
        ->and($out)->not->toContain('creem_test_key');
    expect(Subscription::query()->where('user_id', $user->id)->where('plan_source', Subscription::SOURCE_STAFF_COMP)->exists())->toBeFalse()
        ->and($trial->fresh()->status->value)->toBe('trialing')
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Pending);
});

it('--revoke ends the comp and returns the account to Free, with no Creem call', function () {
    [$user] = pcTrialOwner();
    pcCreem();
    pcRun(['--plan' => 'pro', '--until' => '2027-11-14']);

    [$code, $out] = pcRun(['--revoke' => true]);

    expect($code)->toBe(0)
        ->and($user->fresh()->plan()->key->value)->toBe('free')
        ->and($out)->toBe('owner@example.test: staff comp revoked · plan now Free · Creem: not called');
    Http::assertSentCount(1); // only the first run's cancel
});

it('refuses a past date, an unknown plan and an unknown account, changing nothing', function (array $args, string $message) {
    pcTrialOwner();
    pcCreem();

    [$code, $out] = pcRun($args);

    expect($code)->toBe(1)->and($out)->toContain($message);
    Http::assertNothingSent();
    expect(Subscription::query()->where('plan_source', Subscription::SOURCE_STAFF_COMP)->exists())->toBeFalse();
})->with([
    'past date' => [['--plan' => 'pro', '--until' => '2020-01-01'], 'must be a future date'],
    'today' => [['--plan' => 'pro', '--until' => now()->toDateString()], 'must be a future date'],
    'bad date' => [['--plan' => 'pro', '--until' => 'soon'], 'must be a future date'],
    'free' => [['--plan' => 'free', '--until' => '2027-11-14'], '--plan must be pro or max'],
]);

it('refuses an unknown account', function () {
    [$code, $out] = pcRun(['--plan' => 'pro', '--until' => '2027-11-14']);

    expect($code)->toBe(1)->and($out)->toContain('No account with the email owner@example.test');
    Http::assertNothingSent();
});

it('an account with no Creem subscription is comped without any Creem call', function () {
    User::factory()->create(['email' => 'owner@example.test']);
    pcCreem();

    [$code, $out] = pcRun(['--plan' => 'pro', '--until' => '2027-11-14']);

    expect($code)->toBe(0)->and($out)->toContain('Creem: nothing to cancel');
    Http::assertNothingSent();
});

it('the comp ends on its date through the daily expiry job', function () {
    [$user] = pcTrialOwner();
    pcCreem();
    pcRun(['--plan' => 'pro', '--until' => now()->addDays(10)->toDateString()]);

    $this->travel(11)->days();
    app()->call([new ExpireSubscriptions, 'handle']);

    expect($user->fresh()->plan()->key->value)->toBe('free');
});
