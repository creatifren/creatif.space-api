<?php

use App\Enums\WalletTransactionType;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Support\Wallet;

it('starts empty', function () {
    expect(Wallet::balance(User::factory()->create()))->toBe(0);
});

it('adds a credit and carries the running balance', function () {
    $user = User::factory()->create();

    Wallet::credit($user, WalletTransactionType::SaleCredit, 141_550);
    $second = Wallet::credit($user, WalletTransactionType::SaleCredit, 58_450);

    expect(Wallet::balance($user))->toBe(200_000)
        ->and($second->balance_after)->toBe(200_000)
        ->and($second->amount)->toBe(58_450);
});

it('stores a debit negative, so the sum is the balance', function () {
    $user = User::factory()->create();
    Wallet::credit($user, WalletTransactionType::SaleCredit, 200_000);

    $debit = Wallet::debit($user, WalletTransactionType::WithdrawalDebit, 50_000);

    expect($debit->amount)->toBe(-50_000)
        ->and($debit->balance_after)->toBe(150_000)
        ->and(Wallet::balance($user))->toBe(150_000);
});

it('refuses to overdraw', function () {
    $user = User::factory()->create();
    Wallet::credit($user, WalletTransactionType::SaleCredit, 10_000);

    expect(fn () => Wallet::debit($user, WalletTransactionType::WithdrawalDebit, 10_001))
        ->toThrow(RuntimeException::class);

    // And nothing was written on the way out.
    expect(Wallet::balance($user))->toBe(10_000)
        ->and($user->walletTransactions()->count())->toBe(1);
});

it('refuses a negative or zero amount in either direction', function () {
    $user = User::factory()->create();

    expect(fn () => Wallet::credit($user, WalletTransactionType::SaleCredit, 0))
        ->toThrow(RuntimeException::class)
        ->and(fn () => Wallet::credit($user, WalletTransactionType::SaleCredit, -5))
        ->toThrow(RuntimeException::class)
        ->and(fn () => Wallet::debit($user, WalletTransactionType::WithdrawalDebit, -5))
        ->toThrow(RuntimeException::class);
});

it('puts the money back when a payout fails, without erasing the debit', function () {
    $user = User::factory()->create();
    Wallet::credit($user, WalletTransactionType::SaleCredit, 200_000);
    Wallet::debit($user, WalletTransactionType::WithdrawalDebit, 120_000);

    Wallet::credit($user, WalletTransactionType::WithdrawalFailedCredit, 120_000);

    // Three lines, not one edited twice — the history says what happened.
    expect($user->walletTransactions()->count())->toBe(3)
        ->and(Wallet::balance($user))->toBe(200_000);
});

it('keeps one balance per creator', function () {
    $mine = User::factory()->create();
    $theirs = User::factory()->create();

    Wallet::credit($mine, WalletTransactionType::SaleCredit, 100_000);
    Wallet::credit($theirs, WalletTransactionType::SaleCredit, 900_000);

    expect(Wallet::balance($mine))->toBe(100_000);
});

it('holds a reference back to what moved the money', function () {
    $user = User::factory()->create();

    $line = Wallet::credit(
        $user,
        WalletTransactionType::CommissionCredit,
        141_550,
        'commission',
        4242,
    );

    expect($line->reference_type)->toBe('commission')
        ->and($line->reference_id)->toBe(4242);
});

it('never updates a line once written', function () {
    $user = User::factory()->create();
    $line = Wallet::credit($user, WalletTransactionType::SaleCredit, 10_000);

    // The model has no updated_at at all: a ledger row is a fact, not a
    // record that gets maintained.
    expect(WalletTransaction::UPDATED_AT)->toBeNull()
        ->and($line->getAttributes())->not->toHaveKey('updated_at');
});
