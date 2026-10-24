<?php

use App\Enums\WalletTransactionType;
use App\Models\User;
use App\Support\Wallet;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Prices moved ×100 (a send 3 → 300, a block 50 → 5.000, Premium 120 →
 * 12.000), so every Credits balance moves ×100 with them — what a balance
 * could buy yesterday it can still buy today.
 *
 * Append-only, like every ledger correction: one grant row per account for
 * the 99× difference, never an edit to old rows. A grant is not
 * withdrawable, so what can go back to a bank stays exactly what was paid
 * in rupiah. Sends charged at the old price and still pending come back at
 * the new one (300), which is the old 3 scaled — consistent with the rest.
 */
return new class extends Migration
{
    public const NOTE = 'Converted to the new prices (×100)';

    public function up(): void
    {
        $balances = DB::table('wallet_transactions')
            ->where('wallet', Wallet::CREDITS)
            ->groupBy('user_id')
            ->havingRaw('SUM(amount) > 0')
            ->pluck('user_id');

        foreach ($balances as $userId) {
            $done = DB::table('wallet_transactions')
                ->where('user_id', $userId)
                ->where('wallet', Wallet::CREDITS)
                ->where('note', self::NOTE)
                ->exists();
            $user = User::query()->find($userId);

            if ($done || $user === null) {
                continue;
            }

            // Read and written under Wallet's per-user lock, so a send that
            // lands mid-deploy is either scaled or not, never half.
            DB::transaction(function () use ($user) {
                $balance = Wallet::balance($user, Wallet::CREDITS);
                if ($balance > 0) {
                    Wallet::credit($user, WalletTransactionType::CreditsGrant, $balance * 99,
                        null, null, self::NOTE, Wallet::CREDITS);
                }
            });
        }
    }

    public function down(): void
    {
        // Not reversed: the converted Credits may already be spent, and
        // taking them back could overdraw. Correct by hand if ever needed.
    }
};
