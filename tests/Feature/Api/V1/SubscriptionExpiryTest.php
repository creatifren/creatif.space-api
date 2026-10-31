<?php

use App\Jobs\ExpireSubscriptions;
use App\Models\Subscription;
use App\Services\CreemService;
use Illuminate\Support\Facades\Http;

it('does not invent a grace period from the local clock', function () {
    Http::preventStrayRequests();
    $sub = Subscription::factory()->create(['current_period_end' => now()->subHour()]);
    (new ExpireSubscriptions)->handle(app(CreemService::class));
    expect($sub->fresh()->status->value)->toBe('active');
    Http::assertNothingSent();
});

it('ends access when the past_due grace closes, whatever Creem says', function () {
    Http::preventStrayRequests();
    $sub = Subscription::factory()->create(['status' => 'past_due', 'grace_ends_at' => now()->subMinute()]);
    (new ExpireSubscriptions)->handle(app(CreemService::class));
    expect($sub->fresh()->status->value)->toBe('expired');
});

it('reconciles an overdue remote subscription before expiring it', function () {
    Http::preventStrayRequests();
    Http::fake(['test-api.creem.io/v1/subscriptions*' => Http::response(['id' => 'sub_test', 'status' => 'expired'])]);
    $sub = Subscription::factory()->create(['creem_subscription_id' => 'sub_test', 'current_period_end' => now()->subDays(5)]);
    (new ExpireSubscriptions)->handle(app(CreemService::class));
    expect($sub->fresh()->status->value)->toBe('expired');
});

it('does not grant a new paid period from a status read', function () {
    Http::preventStrayRequests();
    Http::fake(['test-api.creem.io/*' => Http::response(['id' => 'sub_test', 'status' => 'active'])]);
    $sub = Subscription::factory()->create(['creem_subscription_id' => 'sub_test', 'current_period_end' => now()->subDays(5)]);
    $end = $sub->current_period_end;
    (new ExpireSubscriptions)->handle(app(CreemService::class));
    expect($sub->fresh()->current_period_end->eq($end))->toBeTrue();
});
