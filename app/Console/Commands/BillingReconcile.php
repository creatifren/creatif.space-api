<?php

namespace App\Console\Commands;

use App\Enums\InvoiceStatus;
use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Api\V1\CreemWebhookController;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Support\CreemProducts;
use App\Support\ProductEvents;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Repairs "charged but no plan": an account whose latest paid Creem invoice
 * (amount > 0, not refunded, its period still running) belongs to a
 * subscription that is not the one giving access — e.g. the P0 of 14 Nov,
 * where a stale cancelled trial made the paid checkout look like a
 * duplicate. Re-evaluated from what we stored when the payment came in
 * (the invoice's raw_notification), never from a guess, and no Creem call.
 *
 *   php artisan billing:reconcile --dry-run            # list every account in that state (read-only)
 *   php artisan billing:reconcile owner@example.com --dry-run
 *   php artisan billing:reconcile owner@example.com    # activate the paid subscription
 *
 * Activating sets the paid row Active for the paid period (a cancellation
 * already made stays: it will not renew) and closes the rows it replaces;
 * another LIVE paid plan is left alone and named for a manual refund.
 */
class BillingReconcile extends Command
{
    protected $signature = 'billing:reconcile
        {email? : One account (default: every account)}
        {--dry-run : Only say what would change}';

    protected $description = 'Activate the paid Creem subscription of accounts charged but not on that plan (from stored payment data); --dry-run lists them';

    public function handle(): int
    {
        $email = $this->argument('email');
        $dry = (bool) $this->option('dry-run');

        if (is_string($email) && $email !== '') {
            $user = User::query()->where('email', Str::lower(trim($email)))->first();
            if ($user === null) {
                $this->error("No account with the email {$email}. Nothing was changed.");

                return self::FAILURE;
            }
            $users = collect([$user]);
        } else {
            $users = User::query()->whereIn('id', self::candidateUserIds())->orderBy('id')->get();
        }

        $found = 0;
        foreach ($users as $user) {
            $fix = self::fixFor($user);
            if ($fix === null) {
                if (is_string($email) && $email !== '') {
                    $this->line("user {$user->id}: nothing to reconcile (plan {$user->plan()->name}).");
                }

                continue;
            }
            $found++;
            [$subscription, $invoice, $plan, $period, $start, $end, $close, $live] = $fix;

            $what = "user {$user->id}: ".($dry ? 'would activate' : 'activated')
                ." subscription {$subscription->id} ({$subscription->creem_subscription_id}, {$plan->name} {$period}, until {$end->toDateString()})"
                ." from paid invoice {$invoice->number}"
                .($close->isNotEmpty() ? '; '.($dry ? 'would close' : 'closed').' rows '.$close->pluck('id')->implode(', ') : '')
                .($live->isNotEmpty() ? '; ANOTHER LIVE PAID PLAN (rows '.$live->pluck('id')->implode(', ').'): refund by hand in Creem' : '');

            if (! $dry) {
                try {
                    DB::transaction(function () use ($subscription, $plan, $period, $start, $end, $close) {
                        foreach ($close as $old) {
                            $old->update(['status' => SubscriptionStatus::Expired, 'cancelled_at' => $old->cancelled_at ?? now()]);
                        }
                        $subscription->update([
                            'plan_id' => $plan->id, 'billing_period' => $period,
                            'status' => SubscriptionStatus::Active,
                            'current_period_start' => $start, 'current_period_end' => $end,
                            'grace_ends_at' => null,
                        ]);
                    });
                    ProductEvents::planChanged($user->fresh());
                } catch (Throwable $e) {
                    $this->error("user {$user->id}: not changed ({$e->getMessage()}).");

                    return self::FAILURE;
                }
            }
            $this->line($what);
        }

        if ($found === 0 && ! (is_string($email) && $email !== '')) {
            $this->line('No account is charged without its plan.');
        }

        return self::SUCCESS;
    }

    /**
     * Accounts with a paid, unrefunded Creem invoice on a subscription that
     * does not give access (read-only; the same rule as the SQL in the PR).
     *
     * @return list<int>
     */
    public static function candidateUserIds(): array
    {
        return Subscription::query()
            ->whereIn('id', Invoice::query()->select('subscription_id')
                ->where('status', InvoiceStatus::Paid)
                ->whereNotNull('creem_transaction_id')
                ->where('amount', '>', 0))
            ->whereNot(fn ($q) => $q->entitled())
            ->distinct()
            ->pluck('user_id')
            ->all();
    }

    /**
     * What to do for one account, or null when its latest paid payment
     * already gives access (or has run out, or there is none).
     *
     * @return array{0: Subscription, 1: Invoice, 2: Plan, 3: string, 4: CarbonImmutable, 5: CarbonImmutable, 6: Collection<int, Subscription>, 7: Collection<int, Subscription>}|null
     */
    public static function fixFor(User $user): ?array
    {
        $invoice = Invoice::query()
            ->whereIn('subscription_id', $user->subscriptions()->select('id'))
            ->where('status', InvoiceStatus::Paid)
            ->whereNotNull('creem_transaction_id')
            ->where('amount', '>', 0)
            ->latest('paid_at')->latest('id')
            ->first();
        if ($invoice === null) {
            return null;
        }
        $subscription = $invoice->subscription()->first();
        $object = (array) ($invoice->raw_notification ?? []);
        if ($subscription === null || $subscription->creem_subscription_id === null || $subscription->isEntitled()) {
            return null;
        }
        try {
            [$plan, $period] = CreemProducts::plan(self::id($object['product'] ?? null));
            $start = CarbonImmutable::parse((string) ($object['current_period_start_date'] ?? ''));
            $end = CarbonImmutable::parse((string) ($object['current_period_end_date'] ?? ''));
        } catch (Throwable) {
            return null;
        }
        if (! $end->isFuture() || ! $end->gt($start)) {
            return null; // the paid period is over: nothing to give back as access
        }

        $others = $user->subscriptions()->whereKeyNot($subscription->id)->whereIn('status', SubscriptionStatus::entitled())->get();
        $live = $others->filter(fn (Subscription $s) => CreemWebhookController::liveDuplicate($s))->values();
        $close = $others->reject(fn (Subscription $s) => CreemWebhookController::liveDuplicate($s))->values();

        return [$subscription, $invoice, $plan, $period, $start, $end, $close, $live];
    }

    private static function id(mixed $value): ?string
    {
        return is_string($value) ? $value : (is_array($value) && is_string($value['id'] ?? null) ? $value['id'] : null);
    }
}
