<?php

namespace App\Support;

use App\Enums\WalletTransactionType;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The creator's balance. The only thing that writes wallet_transactions.
 *
 * The balance is never a column: it is SUM(amount) over the ledger, and a
 * correction is a new row rather than an edit to an old one (integrity
 * rule §3). `balance_after` is written alongside for auditing — if it ever
 * disagrees with the sum, the sum is the truth and the row is the evidence
 * of when things went wrong.
 */
final class Wallet
{
    /**
     * What this user can withdraw right now.
     */
    public static function balance(User $user): int
    {
        return (int) WalletTransaction::query()
            ->where('user_id', $user->id)
            ->sum('amount');
    }

    /**
     * Money in. Positive amounts only — use debit() for the other direction,
     * so a sign error can't quietly drain someone's balance.
     */
    public static function credit(
        User $user,
        WalletTransactionType $type,
        int $amount,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $note = null,
    ): WalletTransaction {
        if ($amount <= 0) {
            throw new RuntimeException('A credit must be positive.');
        }

        return self::write($user, $type, $amount, $referenceType, $referenceId, $note);
    }

    /**
     * Money out. Takes a positive amount and stores it negative, so callers
     * never have to think about the sign.
     *
     * Refuses to overdraw: the balance is checked inside the same locked
     * transaction that writes the row, so two withdrawals racing each other
     * can't both pass.
     */
    public static function debit(
        User $user,
        WalletTransactionType $type,
        int $amount,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $note = null,
    ): WalletTransaction {
        if ($amount <= 0) {
            throw new RuntimeException('A debit must be positive.');
        }

        return self::write($user, $type, -$amount, $referenceType, $referenceId, $note);
    }

    /**
     * Append one line, with the running balance computed under a lock.
     */
    private static function write(
        User $user,
        WalletTransactionType $type,
        int $signedAmount,
        ?string $referenceType,
        ?int $referenceId,
        ?string $note,
    ): WalletTransaction {
        return DB::transaction(function () use (
            $user, $type, $signedAmount, $referenceType, $referenceId, $note
        ) {
            // Lock this user's ledger for the length of the write, so the
            // balance we check is the balance we act on.
            $current = (int) WalletTransaction::query()
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->sum('amount');

            $after = $current + $signedAmount;

            if ($after < 0) {
                throw new RuntimeException('That would overdraw the balance.');
            }

            return WalletTransaction::query()->create([
                'user_id' => $user->id,
                'type' => $type,
                'amount' => $signedAmount,
                'balance_after' => $after,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'note' => $note,
            ]);
        });
    }
}
