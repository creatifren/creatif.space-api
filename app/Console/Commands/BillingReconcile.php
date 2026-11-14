<?php

namespace App\Console\Commands;

use App\Enums\InvoiceStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\CreemService;
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
 * (the invoice's raw_notification), never from a guess.
 *
 *   php artisan billing:reconcile --dry-run            # list every account in that state (read-only)
 *   php artisan billing:reconcile owner@example.com --dry-run
 *   php artisan billing:reconcile owner@example.com    # activate the paid subscription
 *
 * Never activates (b202611141738), reported as "skipped: reason":
 * - an account that already has a running plan (a staff comp, another paid plan);
 * - a payment refunded or disputed (invoice status, or refund/dispute webhooks);
 * - a subscription ended at Creem: canceled/expired in our webhook history, or
 *   Creem's own status (one read-only GET) not active/trialing/scheduled_cancel
 *   — and when Creem cannot be asked, it is skipped too.
 *
 * Activating sets the paid row Active for the paid period (a cancellation
 * already made stays: it will not renew) and closes the stale rows it replaces.
 */
class BillingReconcile extends Command
{
    protected $signature = 'billing:reconcile
        {email? : One account (default: every account)}
        {--dry-run : Only say what would change}';

    protected $description = 'Activate the paid Creem subscription of accounts charged but not on that plan (from stored payment data); --dry-run lists them';

    /** Creem statuses of a subscription that still has a paid period to give. */
    private const RUNNING_AT_CREEM = ['active', 'trialing', 'scheduled_cancel'];

    public function handle(CreemService $creem): int
    {
        $email = $this->argument('email');
        $one = is_string($email) && $email !== '';
        $dry = (bool) $this->option('dry-run');

        if ($one) {
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
            $fix = self::fixFor($user, $creem);
            if ($fix === null) {
                if ($one) {
                    $this->line("user {$user->id}: nothing to reconcile (plan {$user->plan()->name}).");
                }

                continue;
            }
            $found++;
            if (is_string($fix)) {
                $this->line("user {$user->id}: skipped: {$fix}");

                continue;
            }
            [$subscription, $invoice, $plan, $period, $start, $end, $close] = $fix;

            $what = "user {$user->id}: ".($dry ? 'would activate' : 'activated')
                ." subscription {$subscription->id} ({$subscription->creem_subscription_id}, {$plan->name} {$period}, until {$end->toDateString()})"
                ." from paid invoice {$invoice->number}"
                .($close->isNotEmpty() ? '; '.($dry ? 'would close' : 'closed').' rows '.$close->pluck('id')->implode(', ') : '');

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

        if ($found === 0 && ! $one) {
            $this->line('No account is charged without its plan.');
        }

        return self::SUCCESS;
    }

    /**
     * Accounts with a paid, unrefunded Creem invoice on a subscription that
     * does not give access (read-only).
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
     * What to do for one account: null when its latest paid payment already
     * gives access (or has run out, or there is none); a string saying why it
     * must not be touched; otherwise the fix.
     *
     * @return array{0: Subscription, 1: Invoice, 2: Plan, 3: string, 4: CarbonImmutable, 5: CarbonImmutable, 6: Collection<int, Subscription>}|string|null
     */
    public static function fixFor(User $user, CreemService $creem): array|string|null
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
        $creemId = (string) $subscription->creem_subscription_id;
        $label = "subscription {$subscription->id} ({$creemId}), invoice {$invoice->number}";

        // (c) The account already has a running plan: nothing is missing.
        $running = $user->subscriptions()->whereKeyNot($subscription->id)->entitled()->latest('id')->first();
        if ($running !== null) {
            $what = $running->plan_source === Subscription::SOURCE_STAFF_COMP ? 'a staff comp' : 'another running plan';

            return "{$label}: the account already has {$what} (row {$running->id}, until {$running->current_period_end?->toDateString()})";
        }

        // (b) The payment was refunded or disputed.
        if ($subscription->invoices()->where('status', InvoiceStatus::Refunded)->exists()) {
            return "{$label}: a payment on this subscription was refunded";
        }
        $transactionId = (string) $invoice->creem_transaction_id;
        if (($type = self::history(['refund.created', 'dispute.created'], [$transactionId, $creemId])) !== null) {
            return "{$label}: {$type} in our webhook history";
        }

        // (a) The subscription ended at Creem.
        if (($type = self::history(['subscription.canceled', 'subscription.expired'], [$creemId])) !== null) {
            return "{$label}: {$type} in our webhook history";
        }
        try {
            $remote = $creem->subscription($creemId);
        } catch (Throwable $e) {
            return "{$label}: could not check its status at Creem".($e->getCode() !== 0 ? ' (HTTP '.$e->getCode().')' : '');
        }
        $status = strtolower((string) ($remote['status'] ?? ''));
        if (($remote['id'] ?? null) !== $creemId || ! in_array($status, self::RUNNING_AT_CREEM, true)) {
            return "{$label}: Creem says ".($status === '' ? 'nothing about it' : $status);
        }

        // Rows that only still read as running (a cancelled trial past its period).
        $close = $user->subscriptions()->whereKeyNot($subscription->id)->whereIn('status', SubscriptionStatus::entitled())->get();

        return [$subscription, $invoice, $plan, $period, $start, $end, $close];
    }

    /**
     * The first Creem webhook of one of $types whose payload names one of
     * $ids exactly, or null.
     *
     * @param  list<string>  $types
     * @param  list<string>  $ids
     */
    private static function history(array $types, array $ids): ?string
    {
        $ids = array_values(array_filter($ids, fn (string $id) => $id !== ''));
        if ($ids === []) {
            return null;
        }
        $rows = DB::table('webhook_events')->where('source', 'creem')->whereIn('type', $types)
            ->where(function ($q) use ($ids) {
                foreach ($ids as $id) {
                    // Explicit escape: SQLite has no default LIKE escape character.
                    $q->orWhereRaw("payload LIKE ? ESCAPE '!'", ['%"'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $id).'"%']);
                }
            })
            ->orderBy('id')->get(['type', 'payload']);
        foreach ($rows as $row) {
            $payload = json_decode((string) $row->payload, true);
            if (is_array($payload) && self::names($payload['object'] ?? null, $ids)) {
                return (string) $row->type;
            }
        }

        return null;
    }

    /**
     * Does $value hold one of $ids as a string anywhere (an id or a nested id)?
     *
     * @param  list<string>  $ids
     */
    private static function names(mixed $value, array $ids): bool
    {
        if (is_string($value)) {
            return in_array($value, $ids, true);
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                if (self::names($item, $ids)) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function id(mixed $value): ?string
    {
        return is_string($value) ? $value : (is_array($value) && is_string($value['id'] ?? null) ? $value['id'] : null);
    }
}
