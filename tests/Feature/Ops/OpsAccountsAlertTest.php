<?php

use App\Console\Commands\OpsAccountsAlertCommand;
use App\Models\SocialAccount;
use App\Models\User;
use App\Notifications\OpsAccountsAlert;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

/* Owner alert (backlog #2): connected social accounts reach 80, then 900. */

beforeEach(fn () => Cache::forget(OpsAccountsAlertCommand::CACHE_KEY));

it('stays quiet under 80 and does not count disconnected or sample accounts', function () {
    Notification::fake();
    $user = User::factory()->create();
    SocialAccount::factory()->for($user)->count(79)->create();
    SocialAccount::factory()->for($user)->count(3)->create(['status' => 'disconnected']);
    SocialAccount::factory()->for($user)->count(2)->create(['is_demo' => true]);

    $this->artisan('ops:accounts-alert')->assertSuccessful();

    Notification::assertNothingSent();
});

it('mails once at 80, not again while it stays there', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'owner.private@example.com']);
    SocialAccount::factory()->for($user)->count(80)->create();

    $this->artisan('ops:accounts-alert')->assertSuccessful();
    $this->artisan('ops:accounts-alert')->assertSuccessful();

    Notification::assertSentOnDemandTimes(OpsAccountsAlert::class, 1);
    Notification::assertSentOnDemand(OpsAccountsAlert::class, function (OpsAccountsAlert $n, array $channels, AnonymousNotifiable $to) {
        $mail = $n->toMail($to);

        return $to->routes['mail'] === 'halo@creatif.space'
            && $n->count === 80 && $n->step === 80
            && ! str_contains($mail->subject.' '.implode(' ', $mail->introLines), 'owner.private');
    });
});

it('can mail at 80 again after the count fell under it', function () {
    Notification::fake();
    $user = User::factory()->create();
    $accounts = SocialAccount::factory()->for($user)->count(80)->create();
    $this->artisan('ops:accounts-alert');

    $accounts->first()->update(['status' => 'disconnected']);
    $this->artisan('ops:accounts-alert');
    SocialAccount::factory()->for($user)->create();
    $this->artisan('ops:accounts-alert');

    Notification::assertSentOnDemandTimes(OpsAccountsAlert::class, 2);
});
