<?php

use App\Enums\SubscriptionStatus;
use App\Jobs\ExpireSubscriptions;
use App\Models\Plan;
use App\Models\Space;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\SubscriptionPastDue;
use Illuminate\Support\Facades\Notification;

function premiumId(): int
{
    return (int) Plan::query()->where('key', 'premium')->value('id');
}

it('puts a lapsed plan into grace and says so', function () {
    Notification::fake();
    $user = User::factory()->create();
    $subscription = Subscription::factory()->for($user)->create([
        'plan_id' => premiumId(),
        'current_period_end' => now()->subHour(),
    ]);

    (new ExpireSubscriptions)->handle();

    $subscription->refresh();

    expect($subscription->status)->toBe(SubscriptionStatus::PastDue)
        ->and($subscription->grace_ends_at)->not->toBeNull()
        // Still Premium during grace — that is what grace is for.
        ->and($user->fresh()->plan()->key->value)->toBe('premium');

    Notification::assertSentTo($user, SubscriptionPastDue::class);
});

it('leaves a plan that is still paid for alone', function () {
    Notification::fake();
    $subscription = Subscription::factory()->create([
        'plan_id' => premiumId(),
        'current_period_end' => now()->addWeek(),
    ]);

    (new ExpireSubscriptions)->handle();

    expect($subscription->refresh()->status)->toBe(SubscriptionStatus::Active);
    Notification::assertNothingSent();
});

it('drops to Free when grace runs out', function () {
    Notification::fake();
    $user = User::factory()->create();
    $subscription = Subscription::factory()->for($user)->pastDue()->create([
        'plan_id' => premiumId(),
        'grace_ends_at' => now()->subHour(),
    ]);

    (new ExpireSubscriptions)->handle();

    expect($subscription->refresh()->status)->toBe(SubscriptionStatus::Expired)
        ->and($user->fresh()->plan()->key->value)->toBe('free');
});

it('expires a cancelled plan quietly — they asked for this', function () {
    Notification::fake();
    $user = User::factory()->create();
    $subscription = Subscription::factory()->for($user)->cancelled()->create([
        'plan_id' => premiumId(),
        'current_period_end' => now()->subHour(),
    ]);

    (new ExpireSubscriptions)->handle();

    expect($subscription->refresh()->status)->toBe(SubscriptionStatus::Expired);
    Notification::assertNothingSent();
});

it('never deletes a Space on the way down', function () {
    Notification::fake();
    $user = User::factory()->create();
    Subscription::factory()->for($user)->pastDue()->create([
        'plan_id' => premiumId(),
        'grace_ends_at' => now()->subHour(),
    ]);
    // Far past what Free allows.
    Space::factory()->count(14)->for($user)->published()->create();

    (new ExpireSubscriptions)->handle();

    expect($user->fresh()->plan()->key->value)->toBe('free')
        ->and($user->spaces()->count())->toBe(14)
        // Published ones stay published: the promise is "readable archives",
        // not "unpublished behind your back".
        ->and($user->spaces()->where('status', 'published')->count())->toBe(14);
});

it('is safe to run twice in a row', function () {
    Notification::fake();
    $user = User::factory()->create();
    $subscription = Subscription::factory()->for($user)->create([
        'plan_id' => premiumId(),
        'current_period_end' => now()->subHour(),
    ]);

    (new ExpireSubscriptions)->handle();
    $graceEnd = $subscription->refresh()->grace_ends_at;

    (new ExpireSubscriptions)->handle();

    expect($subscription->refresh()->grace_ends_at->eq($graceEnd))->toBeTrue();
    Notification::assertSentToTimes($user, SubscriptionPastDue::class, 1);
});
