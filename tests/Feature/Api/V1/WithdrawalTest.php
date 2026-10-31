<?php

use App\Enums\WalletTransactionType;
use App\Enums\WithdrawalStatus;
use App\Models\User;
use App\Models\Withdrawal;
use App\Support\Credits;
use App\Support\Wallet;

function funded(int $amount = 5000): User
{
    $user = User::factory()->create();
    Wallet::credit($user, WalletTransactionType::SaleCredit, $amount);

    return $user;
}

it('saves a valid PayPal email and pays out to it', function () {
    $user = funded();
    $this->actingAs($user)->putJson('/api/v1/me/payout-account', ['paypal_email' => 'rani@example.com'])
        ->assertOk()->assertJsonPath('data.paypal_email', 'rani@example.com');
    $this->actingAs($user)->postJson('/api/v1/me/withdrawals', ['amount' => 1000])
        ->assertCreated()->assertJsonPath('data.paypal_email', 'rani@example.com')->assertJsonPath('data.currency', 'USD');
    expect(Wallet::balance($user))->toBe(4000);
});

it('refuses invalid or missing PayPal destinations', function () {
    $user = funded();
    $this->actingAs($user)->putJson('/api/v1/me/payout-account', ['paypal_email' => 'nope'])->assertUnprocessable();
    $this->actingAs($user)->postJson('/api/v1/me/withdrawals', ['amount' => 1000])
        ->assertUnprocessable()->assertJsonValidationErrors('paypal_email');
});

it('holds the ten-dollar minimum and balance limit', function () {
    $user = funded(1500);
    foreach ([999, 1501] as $amount) {
        $this->actingAs($user)->postJson('/api/v1/me/withdrawals', ['amount' => $amount, 'paypal_email' => 'a@example.com'])
            ->assertUnprocessable();
    }
    $this->actingAs($user)->postJson('/api/v1/me/withdrawals', ['amount' => 1000, 'paypal_email' => 'a@example.com'])->assertCreated();
});

it('allows only one open payout per wallet', function () {
    $user = funded();
    $body = ['amount' => 1000, 'paypal_email' => 'a@example.com'];
    $this->actingAs($user)->postJson('/api/v1/me/withdrawals', $body)->assertCreated();
    $this->actingAs($user)->postJson('/api/v1/me/withdrawals', $body)->assertUnprocessable();
    $user->withdrawals()->update(['status' => WithdrawalStatus::Completed]);
    $this->actingAs($user)->postJson('/api/v1/me/withdrawals', $body)->assertCreated();
});

it('lists only my payout history', function () {
    $user = User::factory()->create();
    Withdrawal::factory()->for($user)->completed()->create(['amount' => 2500, 'payout_reference' => 'PAYPAL-1']);
    Withdrawal::factory()->create();
    $this->actingAs($user)->getJson('/api/v1/me/withdrawals')->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.payout_reference', 'PAYPAL-1');
});

it('refuses to pay Credits out', function () {
    $user = User::factory()->create();
    Wallet::credit($user, WalletTransactionType::CreditsTopUp, 100000, wallet: Wallet::CREDITS);
    $this->actingAs($user)->postJson('/api/v1/me/withdrawals', ['amount' => 1000, 'paypal_email' => 'a@example.com', 'wallet' => 'credits'])
        ->assertUnprocessable()->assertJsonValidationErrors('wallet');
    expect(Credits::balance($user))->toBe(100000);
});

it('rejects guests', function () {
    $this->postJson('/api/v1/me/withdrawals', ['amount' => 1000])->assertUnauthorized();
});
