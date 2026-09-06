<?php

namespace App\Jobs;

use App\Models\Activity;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The ledger keeps 90 days, the same window as raw Space events — and
 * three times as far as the screen's widest range picker, so the answer to
 * "what did I do last month" is always there and the table never grows
 * without a ceiling.
 */
class PruneActivityLog implements ShouldQueue
{
    use Queueable;

    public const KEEP_DAYS = 90;

    private const CHUNK = 5000;

    private const MAX_CHUNKS = 20;

    public int $tries = 1;

    public function handle(): void
    {
        $cutoff = now()->subDays(self::KEEP_DAYS);

        for ($i = 0; $i < self::MAX_CHUNKS; $i++) {
            /* Ids first, then delete by key — DELETE ... LIMIT is a MySQL
               extension and the suite runs on SQLite. Same shape as
               PruneSpaceEvents. */
            $ids = Activity::query()
                ->where('created_at', '<', $cutoff)
                ->limit(self::CHUNK)
                ->pluck('id');

            if ($ids->isEmpty()) {
                return;
            }

            Activity::whereIn('id', $ids)->delete();
        }
    }
}
