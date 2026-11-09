<?php

namespace App\Console\Commands;

use App\Enums\InvoiceStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Models\User;
use App\Services\CreemService;
use App\Support\CreemTrial;
use App\Support\TrialInvoiceRepair;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Before trial support, the $0 subscription.paid that opens a Creem trial
 * was refused (422) and subscription.trialing ignored, so anyone who
 * started a trial was left on Free until the first real charge — or, where
 * the $0 card check was taken for a payment, recorded as Active with no
 * trial end. This asks Creem about every subscription in either state:
 *
 * - Expired, bound to Creem, never started;
 * - Active, bound to Creem, with at most one paid invoice (a trial's card
 *   check may have been recorded as $0, or at the plan's list price);
 *
 * and turns the ones Creem says are trialing into Trialing, with Creem's
 * period. First each Paid invoice is checked against its Creem
 * transaction and corrected to what Creem really took (TrialInvoiceRepair);
 * if any of them is a real charge, the subscription is left alone.
 * Credits are not touched; trial_started fires at most once. Safe to run
 * again; --dry-run prints every planned correction and changes nothing.
 */
class ReconcileTrials extends Command
{
    protected $signature = 'billing:reconcile-trials {--dry-run : Report only, change nothing}';

    protected $description = 'Start local trials for Creem subscriptions that are trialing but still Free here';

    public function handle(CreemService $creem): int
    {
        $candidates = Subscription::query()
            ->whereNotNull('creem_subscription_id')
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->where('status', SubscriptionStatus::Expired)->whereNull('current_period_start'))
                ->orWhere(fn ($q) => $q->where('status', SubscriptionStatus::Active)
                    ->has('invoices', '<=', 1, 'and', fn ($i) => $i->where('status', InvoiceStatus::Paid))))
            ->orderBy('id')
            ->get();

        $started = 0;
        foreach ($candidates as $subscription) {
            try {
                $remote = $creem->subscription((string) $subscription->creem_subscription_id);
            } catch (Throwable $e) {
                $this->error("{$subscription->ulid} (user {$subscription->user_id}): {$e->getMessage()}");

                continue;
            }
            $status = $remote['status'] ?? null;
            $label = "{$subscription->ulid} (user {$subscription->user_id}, {$subscription->status->value} here)";
            if (($remote['id'] ?? null) !== $subscription->creem_subscription_id || $status !== 'trialing') {
                $this->line("{$label}: Creem says ".json_encode($status).' — left as is');

                continue;
            }
            try {
                $corrections = TrialInvoiceRepair::plan($subscription, $creem);
            } catch (Throwable $e) {
                $this->warn("{$label}: trialing in Creem, but {$e->getMessage()} — left as is");

                continue;
            }
            foreach (TrialInvoiceRepair::describe($subscription, $corrections) as $line) {
                $this->line("{$label}: {$line}");
            }
            if ($this->option('dry-run')) {
                $this->info("{$label}: trialing in Creem until ".($remote['current_period_end_date'] ?? '?').' — would start');

                continue;
            }

            $did = DB::transaction(function () use ($subscription, $remote, $corrections): bool {
                $row = Subscription::query()->whereKey($subscription->id)->lockForUpdate()->firstOrFail();
                User::query()->whereKey($row->user_id)->lockForUpdate()->firstOrFail();
                TrialInvoiceRepair::apply($row, $corrections);

                return CreemTrial::start($row, $remote);
            });
            $started += $did ? 1 : 0;
            $this->info("{$label}: ".($did ? 'trial started' : 'already handled'));
        }

        $this->info("{$candidates->count()} checked, {$started} trial(s) started.");

        return self::SUCCESS;
    }
}
