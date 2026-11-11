<?php

namespace App\Console\Commands;

use App\Enums\UserStatus;
use App\Models\User;
use App\Support\AccountDeletion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Daily: accounts whose deletion was asked for more than GRACE_DAYS ago
 * are purged (AccountDeletion::purge). One at a time, in chunks; a failure
 * is reported and that account is tried again on the next run. A purged
 * account is no longer pending, so a re-run does nothing.
 */
class PurgeDeletedAccounts extends Command
{
    protected $signature = 'accounts:purge-deleted';

    protected $description = 'Purge accounts whose deletion grace period has passed';

    public function handle(): int
    {
        $purged = 0;
        $failed = 0;

        User::query()
            ->where('status', UserStatus::PendingDeletion)
            ->whereNotNull('deletion_requested_at')
            ->where('deletion_requested_at', '<=', now()->subDays(AccountDeletion::GRACE_DAYS))
            ->chunkById(50, function ($users) use (&$purged, &$failed): void {
                foreach ($users as $user) {
                    try {
                        AccountDeletion::purge($user);
                        $purged++;
                    } catch (Throwable $e) {
                        report($e);
                        $failed++;
                    }
                }
            });

        Log::info('accounts.purge_run', ['purged' => $purged, 'failed' => $failed]);
        $this->info("Purged {$purged} account(s); {$failed} failed.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
