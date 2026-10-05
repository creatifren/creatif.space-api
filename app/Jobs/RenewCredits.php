<?php

namespace App\Jobs;

use App\Models\User;
use App\Models\WalletTransaction;
use App\Support\Credits;
use App\Support\Wallet;
use App\Support\Workspace;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * The first of the month: rent first, then the plan's Credits, for every
 * account that owns either. Members are skipped — their storage and
 * Credits are the owner's.
 *
 * Idempotent per month: an account with any Credits movement from this
 * job since the 1st is skipped, so a retried job never charges twice.
 */
class RenewCredits implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function handle(): void
    {
        $month = now()->format('F');

        User::query()
            ->where(fn ($q) => $q->where('storage_blocks', '>', 0)
                ->orWhereHas('subscriptions', fn ($s) => $s->where('status', 'active')))
            ->lazyById()
            ->each(function (User $user) use ($month) {
                if (Workspace::isMember($user) || $this->done($user, $month)) {
                    return;
                }

                if (Credits::monthly($user) === 'lapsed') {
                    Log::info('credits.rent_lapsed', ['user' => $user->id]);
                }
            });
    }

    private function done(User $user, string $month): bool
    {
        return WalletTransaction::query()
            ->where('user_id', $user->id)
            ->where('wallet', Wallet::CREDITS)
            ->where('created_at', '>=', now()->startOfMonth())
            ->where(fn ($q) => $q->where('note', 'like', "% · {$month}")->orWhere('note', "{$month} Credits"))
            ->exists();
    }
}
