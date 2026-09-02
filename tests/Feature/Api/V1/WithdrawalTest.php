<?php

use App\Enums\WalletTransactionType;
use App\Enums\WithdrawalStatus;
use App\Models\User;
use App\Models\Withdrawal;
use App\Support\Wallet;

/** A creator with money in the balance. */
function funded(int $amount = 500_000): User
{
    $user = User::factory()->create();
    Wallet::credit($user, WalletTransactionType::SaleCredit, $amount);

    return $user;
}

/**
 * @return array<string, mixed>
 */
function payoutDetails(int $amount): array
{
    return [
        'amount' => $amount,
        'bank_code' => 'bca',
        'account_number' => '1234564471',
        'account_name' => 'Rani Prameswari',
    ];
}

describe('payout account', function () {
    it('saves a payout account and hands it back masked', function () {
        $user = User::factory()->create();

        $this->actingAs($user)->putJson('/api/v1/me/payout-account', [
            'bank_code' => 'bca',
            'account_number' => '1234564471',
            'account_name' => 'Rani Prameswari',
        ])
            ->assertOk()
            ->assertJsonPath('data.bank_code', 'bca')
            ->assertJsonPath('data.account_masked', '••••4471')
            ->assertJsonPath('data.account_name', 'Rani Prameswari');

    });

    it('withdraws to the saved account when no details are sent', function () {
        $user = funded();
        $user->forceFill([
            'payout_bank_code' => 'bni',
            'payout_account_number' => '9876541234',
            'payout_account_name' => 'Rani Prameswari',
        ])->save();

        $this->actingAs($user)
            ->postJson('/api/v1/me/withdrawals', ['amount' => 100_000])
            ->assertCreated()
            ->assertJsonPath('data.bank_code', 'bni')
            ->assertJsonPath('data.account_masked', '••••1234');
    });

    it('refuses a detail-less withdrawal when nothing is saved', function () {
        $this->actingAs(funded())
            ->postJson('/api/v1/me/withdrawals', ['amount' => 100_000])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('account_number');
    });
});

describe('withdrawing', function () {
    it('moves the money out of the balance straight away', function () {
        $user = funded(500_000);

        $this->actingAs($user)->postJson('/api/v1/me/withdrawals', payoutDetails(200_000))
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            // The full number never comes back out.
            ->assertJsonPath('data.account_masked', '••••4471');

        expect(Wallet::balance($user))->toBe(300_000)
            ->and($user->withdrawals()->count())->toBe(1);
    });

    it('refuses more than the balance', function () {
        $user = funded(100_000);

        $this->actingAs($user)->postJson('/api/v1/me/withdrawals', payoutDetails(100_001))
            ->assertUnprocessable();

        expect(Wallet::balance($user))->toBe(100_000);
    });

    it('holds the Rp50.000 minimum the pricing page promises', function () {
        $user = funded(500_000);

        $this->actingAs($user)->postJson('/api/v1/me/withdrawals', payoutDetails(49_999))
            ->assertUnprocessable();

        $this->actingAs($user)->postJson('/api/v1/me/withdrawals', payoutDetails(50_000))
            ->assertCreated();
    });

    it('allows only one in flight at a time', function () {
        $user = funded(500_000);

        $this->actingAs($user)->postJson('/api/v1/me/withdrawals', payoutDetails(100_000))
            ->assertCreated();

        // A second would be paid out of a balance the first already spent.
        $this->actingAs($user)->postJson('/api/v1/me/withdrawals', payoutDetails(100_000))
            ->assertUnprocessable();

        expect(Wallet::balance($user))->toBe(400_000);
    });

    it('lets another through once the first has landed', function () {
        $user = funded(500_000);

        $this->actingAs($user)->postJson('/api/v1/me/withdrawals', payoutDetails(100_000))
            ->assertCreated();

        $user->withdrawals()->first()->forceFill([
            'status' => WithdrawalStatus::Completed,
            'processed_at' => now(),
        ])->save();

        $this->actingAs($user)->postJson('/api/v1/me/withdrawals', payoutDetails(100_000))
            ->assertCreated();

        expect(Wallet::balance($user))->toBe(300_000);
    });

    it('rejects guests', function () {
        $this->postJson('/api/v1/me/withdrawals', payoutDetails(50_000))->assertUnauthorized();
        $this->getJson('/api/v1/me/withdrawals')->assertUnauthorized();
    });

    it('lists my payout history', function () {
        $user = User::factory()->create();
        Withdrawal::factory()->for($user)->completed()->create(['amount' => 640_000]);
        Withdrawal::factory()->create(); // someone else's

        $this->actingAs($user)->getJson('/api/v1/me/withdrawals')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.amount', 640_000)
            ->assertJsonPath('data.0.status', 'completed');
    });
});
