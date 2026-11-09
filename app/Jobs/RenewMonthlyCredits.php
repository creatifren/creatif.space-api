<?php

namespace App\Jobs;

use App\Models\User;
use App\Support\Credits;
use App\Support\Workspace;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Daily: every owner whose Monthly Credits cycle turned today (or on a day
 * the job missed) has the old cycle's leftover expired and the new
 * cycle's Credits granted — a ledger row each. Cycles follow the sign-up
 * day (Free) or the subscription's start (paid), so this runs every day
 * rather than on the 1st. Members are skipped: the Credits are the owner's.
 *
 * Idempotent: Credits::renewMonthly does nothing once the cycle has its
 * grant. Sends and balance reads call it too, so a late job never lets a
 * new cycle spend last cycle's Credits.
 */
class RenewMonthlyCredits implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function handle(): void
    {
        User::query()->lazyById()->each(function (User $user) {
            if (! Workspace::isMember($user)) {
                Credits::renewMonthly($user);
            }
        });
    }
}
