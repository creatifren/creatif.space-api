<?php

namespace App\Jobs;

use App\Enums\CommissionStatus;
use App\Enums\WalletTransactionType;
use App\Models\Commission;
use App\Support\Wallet;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Commission that has finished holding becomes a balance.
 *
 * This is the only place affiliate money enters the ledger, and it lands
 * in the `affiliate` wallet — the product promises the two balances stay
 * apart, and Wallet's per-wallet sum is what keeps that true.
 *
 * Each row is its own transaction: one commission that cannot be credited
 * must not hold up everyone else's payday.
 */
class ReleaseCommissions implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function handle(): void
    {
        $due = Commission::query()
            ->where('status', CommissionStatus::Holding)
            ->where('hold_until', '<=', now())
            ->with('referral.affiliate.user')
            ->get();

        foreach ($due as $commission) {
            $this->release($commission);
        }
    }

    private function release(Commission $commission): void
    {
        $user = $commission->referral->affiliate->user;

        try {
            DB::transaction(function () use ($commission, $user): void {
                // The status moves inside the same transaction as the
                // credit, so a crash between them cannot pay twice.
                $commission->forceFill([
                    'status' => CommissionStatus::Released,
                    'released_at' => now(),
                ])->save();

                Wallet::credit(
                    $user,
                    WalletTransactionType::CommissionCredit,
                    $commission->amount,
                    'commission',
                    $commission->id,
                    wallet: Wallet::AFFILIATE,
                );
            });
        } catch (Throwable $e) {
            Log::error('Commission '.$commission->id.' could not be released: '.$e->getMessage());
        }
    }
}
