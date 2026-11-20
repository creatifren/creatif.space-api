<?php

use App\Enums\WalletTransactionType;
use App\Models\User;
use App\Notifications\CreditsLow;
use App\Support\Credits;
use App\Support\Wallet;
use Illuminate\Support\Facades\Notification;

/*
 * P10 · backlog #2: Credits under 20% of the plan's Monthly Credits →
 * bell + email "Credits running low", once per cycle.
 */

function lowSpend(User $user, int $amount): void
{
    Wallet::debit($user, WalletTransactionType::CreditsSend, $amount, null, null, 'test', Wallet::MONTHLY);
}

it('says nothing above 20% of the monthly Credits', function () {
    Notification::fake();
    $user = User::factory()->create(); // Free: 3,000 monthly
    Credits::renewMonthly($user);
    lowSpend($user, 2000); // 1,000 left = 33%

    CreditsLow::checkAndSend($user);

    Notification::assertNothingSentTo($user);
});

it('sends bell + email once per cycle under 20%', function () {
    Notification::fake();
    $user = User::factory()->create();
    Credits::renewMonthly($user);
    lowSpend($user, 2500); // 500 left = 16%

    CreditsLow::checkAndSend($user);

    Notification::assertSentTo($user, CreditsLow::class, function (CreditsLow $n, array $via) use ($user) {
        return $n->left === 500 && $n->monthly === 3000 && $n->percent() === 16
            && in_array('mail', $via, true) && in_array('database', $via, true)
            && $n->toArray($user)['type'] === 'credits.low';
    });
});

it('does not repeat in the same cycle', function () {
    $user = User::factory()->create();
    Credits::renewMonthly($user);
    lowSpend($user, 2900);

    CreditsLow::checkAndSend($user);
    CreditsLow::checkAndSend($user);

    expect($user->notifications()->where('data->type', 'credits.low')->count())->toBe(1);
});

it('counts top-up Credits toward what is left', function () {
    Notification::fake();
    $user = User::factory()->create();
    Credits::renewMonthly($user);
    lowSpend($user, 2900);
    Credits::grant($user, 5000, 'test');

    CreditsLow::checkAndSend($user);

    Notification::assertNothingSentTo($user);
});

it('is never low on a plan without Monthly Credits', function () {
    expect(CreditsLow::isLow(0, 0))->toBeFalse()
        ->and(CreditsLow::isLow(599, 3000))->toBeTrue()
        ->and(CreditsLow::isLow(600, 3000))->toBeFalse();
});
