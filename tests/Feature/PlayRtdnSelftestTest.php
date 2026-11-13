<?php

use App\Models\Subscription;
use App\Models\User;
use App\Services\Analytics;
use App\Support\GooglePlayBilling;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/*
 * play:rtdn-selftest — a signed fake Pub/Sub RTDN through our own webhook,
 * with Google's side simulated. Local / testing only.
 */

beforeEach(function () {
    Analytics::fake();
    Http::preventStrayRequests();
    config(['services.google_play.package_name' => 'space.creatif.app']);
});

it('delivers a test notification through the real webhook and token check', function () {
    $this->artisan('play:rtdn-selftest')
        ->expectsOutputToContain('Webhook: HTTP 200, outcome: test')
        ->assertSuccessful();

    expect(Cache::has('google-id-token:jwks'))->toBeFalse();
});

it('checks the push against this install\'s configured audience and service account', function () {
    config([
        'services.google_play.rtdn_audience' => 'https://api.creatif.space/api/v1/webhooks/google-play',
        'services.google_play.rtdn_service_account_email' => 'rtdn-push@example-project.iam.gserviceaccount.com',
    ]);

    $this->artisan('play:rtdn-selftest')->expectsOutputToContain('outcome: test')->assertSuccessful();
});

it('maps each Google state onto the user\'s Play subscription, as a real notification would', function (string $type, string $state, string $status, string $plan) {
    $user = User::factory()->create();

    $this->artisan('play:rtdn-selftest', ['--type' => $type, '--state' => $state, '--user' => $user->ulid, '--token' => 'tok-'.$type])
        ->expectsOutputToContain('Seeded an active Play purchase')
        ->expectsOutputToContain('Webhook: HTTP 200, outcome: applied')
        ->expectsOutputToContain("User's plan now: {$plan}")
        ->assertSuccessful();

    $row = Subscription::query()->where('google_purchase_token_hash', GooglePlayBilling::hash('tok-'.$type))->sole();
    expect($row->user_id)->toBe($user->id)
        ->and($row->provider)->toBe('google')
        ->and($row->status->value)->toBe($status);
})->with([
    'renewed → active' => ['renewed', 'ACTIVE', 'active', 'pro'],
    'canceled → cancelled, entitled until expiry' => ['canceled', 'CANCELED', 'cancelled', 'pro'],
    'on hold → past due' => ['on_hold', 'ON_HOLD', 'past_due', 'pro'],
    'expired → expired' => ['expired', 'EXPIRED', 'expired', 'free'],
    'revoked (refund) → expired now' => ['revoked', 'ACTIVE', 'expired', 'free'],
]);

it('expires the plan on a voided purchase', function () {
    $user = User::factory()->create(['email' => 'tester@studio.test']);

    $this->artisan('play:rtdn-selftest', ['--type' => 'voided', '--user' => 'tester@studio.test', '--token' => 'tok-void'])
        ->expectsOutputToContain('outcome: voided')
        ->assertSuccessful();

    expect(Subscription::query()->where('google_purchase_token_hash', GooglePlayBilling::hash('tok-void'))->sole()->status->value)->toBe('expired');
});

it('reports a token with no subscription as unknown, without a user', function () {
    $this->artisan('play:rtdn-selftest', ['--type' => 'renewed', '--token' => 'nobody'])
        ->expectsOutputToContain('outcome: unknown_token')
        ->assertSuccessful();
});

it('refuses outside local and testing, an unknown type, and an unknown user', function () {
    $this->artisan('play:rtdn-selftest', ['--type' => 'nope'])->assertFailed();
    $this->artisan('play:rtdn-selftest', ['--type' => 'renewed', '--user' => 'nobody@nowhere.test'])->assertFailed();

    app()->detectEnvironment(fn () => 'production');
    $this->artisan('play:rtdn-selftest')
        ->expectsOutputToContain('runs only in local or testing')
        ->assertFailed();
    app()->detectEnvironment(fn () => 'testing');
});
