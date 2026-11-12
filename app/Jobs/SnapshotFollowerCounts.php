<?php

namespace App\Jobs;

use App\Models\SocialAccount;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Once a day, a follower count for every usable account — Insight reports
 * read a period's start and end from these snapshots, because the relay
 * only knows the number today. Each account goes through the ordinary
 * RefreshFollowerCount (same STALE_HOURS guard, same X skip), so an account
 * someone looked at this morning is not asked twice.
 */
class SnapshotFollowerCounts implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        SocialAccount::query()
            ->usable()
            ->select('id')
            ->chunkById(500, function ($accounts): void {
                foreach ($accounts as $account) {
                    RefreshFollowerCount::dispatch($account->id);
                }
            });
    }
}
