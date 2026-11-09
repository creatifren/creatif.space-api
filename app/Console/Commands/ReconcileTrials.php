<?php

namespace App\Console\Commands;

use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Models\User;
use App\Services\CreemService;
use App\Support\CreemTrial;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Before trial support, the $0 subscription.paid that opens a Creem trial
 * was refused (422) and subscription.trialing ignored, so anyone who
 * started a trial was left on Free until the first real charge. This asks
 * Creem about every checkout that was bound to a Creem subscription but
 * never started, and starts the ones Creem says are trialing. Safe to run
 * again; --dry-run only reports.
 */
class ReconcileTrials extends Command
{
    protected $signature = 'billing:reconcile-trials {--dry-run : Report only, change nothing}';

    protected $description = 'Start local trials for Creem subscriptions that are trialing but still Free here';

    public function handle(CreemService $creem): int
    {
        $candidates = Subscription::query()
            ->where('status', SubscriptionStatus::Expired)
            ->whereNotNull('creem_subscription_id')
            ->whereNull('current_period_start')
            ->get();

        $started = 0;
        foreach ($candidates as $subscription) {
            try {
                $remote = $creem->subscription((string) $subscription->creem_subscription_id);
            } catch (Throwable $e) {
                $this->error("{$subscription->ulid}: {$e->getMessage()}");

                continue;
            }
            $status = $remote['status'] ?? null;
            if (($remote['id'] ?? null) !== $subscription->creem_subscription_id || $status !== 'trialing') {
                $this->line("{$subscription->ulid}: Creem says ".json_encode($status).' — left as is');

                continue;
            }
            if ($this->option('dry-run')) {
                $this->info("{$subscription->ulid}: trialing in Creem — would start");

                continue;
            }

            $did = DB::transaction(function () use ($subscription, $remote): bool {
                $row = Subscription::query()->whereKey($subscription->id)->lockForUpdate()->firstOrFail();
                User::query()->whereKey($row->user_id)->lockForUpdate()->firstOrFail();

                return CreemTrial::start($row, $remote);
            });
            $started += $did ? 1 : 0;
            $this->info("{$subscription->ulid}: ".($did ? 'trial started' : 'already handled'));
        }

        $this->info("{$candidates->count()} checked, {$started} trial(s) started.");

        return self::SUCCESS;
    }
}
