<?php

use App\Enums\WalletTransactionType;
use App\Enums\WithdrawalStatus;
use App\Models\Order;
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

describe('earnings', function () {
    it('reports a zero balance before anything is sold', function () {
        $this->actingAs(User::factory()->create())
            ->getJson('/api/v1/me/earnings')
            ->assertOk()
            ->assertJsonPath('data.balance', 0)
            ->assertJsonPath('data.fee_percent', 5)
            ->assertJsonPath('data.minimum_withdrawal', 50_000);
    });

    it('separates what is settled from what is still pending', function () {
        $user = funded(141_550);
        Order::factory()->for($user, 'creator')->paid()->create([
            'amount' => 149_000, 'fee_amount' => 7_450, 'net_amount' => 141_550,
        ]);
        // Sold but unpaid: real, but not yet withdrawable.
        Order::factory()->for($user, 'creator')->create([
            'amount' => 100_000, 'fee_amount' => 5_000, 'net_amount' => 95_000,
        ]);

        $this->actingAs($user)->getJson('/api/v1/me/earnings')
            ->assertOk()
            ->assertJsonPath('data.balance', 141_550)
            ->assertJsonPath('data.pending', 95_000)
            ->assertJsonPath('data.month_gross', 149_000)
            ->assertJsonPath('data.month_fee', 7_450);
    });

    it('lists my sales and nobody else’s', function () {
        $user = User::factory()->create();
        Order::factory()->for($user, 'creator')->paid()->create();
        Order::factory()->paid()->create();

        $this->actingAs($user)->getJson('/api/v1/me/orders')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    });

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

        // The summary carries it too, still masked.
        $this->actingAs($user)->getJson('/api/v1/me/earnings')
            ->assertOk()
            ->assertJsonPath('data.payout_account.account_masked', '••••4471');
    });

    it('reports no payout account before one is saved', function () {
        $this->actingAs(User::factory()->create())
            ->getJson('/api/v1/me/earnings')
            ->assertOk()
            ->assertJsonPath('data.payout_account', null);
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
        $this->getJson('/api/v1/me/earnings')->assertUnauthorized();
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
