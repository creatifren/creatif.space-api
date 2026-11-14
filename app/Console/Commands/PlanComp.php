<?php

namespace App\Console\Commands;

use App\Enums\InvoiceStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\CreemService;
use App\Support\ProductEvents;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Staff give an EXISTING account a plan until a date, outside Creem:
 *
 *   php artisan plan:comp owner@example.com --plan=pro --until=2027-11-14
 *   php artisan plan:comp owner@example.com --revoke
 *
 * 1. A staff_comp subscription row (plan_source staff_comp, no Creem id):
 *    the plan's own limits (Pro: 150 publishes, 45,000 Credits a month)
 *    until the end of --until (app timezone); the daily ExpireSubscriptions
 *    job ends it then.
 * 2. Every running Creem trial/subscription of the account is cancelled at
 *    Creem IMMEDIATELY (mode immediate: no renewal, no charge) and closed
 *    on our side. Creem's later webhooks act only on that Creem row, never
 *    on the comp, so they cannot drop the account to Free while it runs.
 *    Creem is called before anything is written: if it refuses, nothing in
 *    our database changes and the command fails with the reason.
 * 3. The account's open (pending) invoices are voided (status expired), so
 *    they are never shown as due or settled. They are our rows; Creem
 *    holds no invoice for them.
 * 4. Idempotent: a rerun finds the comp and nothing to cancel; a new date
 *    or plan moves the same comp.
 *
 * --revoke ends the comp (Free from now) and calls Creem for nothing.
 * Prints one line; never a key, token or checkout link.
 */
class PlanComp extends Command
{
    protected $signature = 'plan:comp
        {email : The existing account\'s email address}
        {--plan=pro : pro or max}
        {--until= : Last day of the comp (YYYY-MM-DD, in the future)}
        {--revoke : End the comp now (back to Free); no Creem call}';

    protected $description = 'Give an account a staff-comped plan until a date (cancels its Creem trial/subscription at once, voids open invoices), or revoke it';

    public function handle(CreemService $creem): int
    {
        $email = Str::lower(trim((string) $this->argument('email')));
        $user = User::query()->where('email', $email)->first();
        if ($user === null) {
            $this->error("No account with the email {$email}. Nothing was changed.");

            return self::FAILURE;
        }

        if ($this->option('revoke')) {
            return $this->revoke($user, $email);
        }

        $key = Str::lower((string) $this->option('plan'));
        if (! in_array($key, ['pro', 'max'], true)) {
            $this->error('--plan must be pro or max. Nothing was changed.');

            return self::FAILURE;
        }
        $until = $this->until();
        if ($until === null) {
            $this->error('--until must be a future date (YYYY-MM-DD, after today). Nothing was changed.');

            return self::FAILURE;
        }
        $plan = Plan::query()->where('key', $key)->firstOrFail();

        // Creem first: a refusal leaves our side exactly as it was.
        $running = $user->subscriptions()
            ->whereNotNull('creem_subscription_id')
            ->where(fn ($q) => $q->whereNull('plan_source')->orWhere('plan_source', '!=', Subscription::SOURCE_STAFF_COMP))
            ->entitled()
            ->get();
        foreach ($running as $subscription) {
            try {
                $creem->cancelNow($subscription);
            } catch (Throwable $e) {
                $status = $e->getCode() !== 0 ? ' (HTTP '.$e->getCode().')' : '';
                $this->error("Creem refused to cancel {$subscription->creem_subscription_id}{$status}; nothing was changed in our database. Try again, or cancel it in the Creem dashboard first.");

                return self::FAILURE;
            }
        }

        $voided = DB::transaction(function () use ($user, $plan, $until, $running) {
            foreach ($running as $subscription) {
                $subscription->forceFill([
                    'status' => SubscriptionStatus::Expired,
                    'cancelled_at' => $subscription->cancelled_at ?? now(),
                    'current_period_end' => now(),
                    'grace_ends_at' => null,
                ])->save();
            }

            $comp = $user->subscriptions()->where('plan_source', Subscription::SOURCE_STAFF_COMP)->entitled()->latest('id')->first();
            if ($comp === null) {
                $user->subscriptions()->create([
                    'plan_id' => $plan->id,
                    'plan_source' => Subscription::SOURCE_STAFF_COMP,
                    'billing_period' => 'yearly',
                    'status' => SubscriptionStatus::Active,
                    'seats' => 1,
                    'current_period_start' => now(),
                    'current_period_end' => $until,
                ]);
            } elseif ($comp->plan_id !== $plan->id || ! $comp->current_period_end?->equalTo($until)) {
                $comp->forceFill(['plan_id' => $plan->id, 'current_period_end' => $until, 'cancelled_at' => null])->save();
            }

            $open = Invoice::query()
                ->whereIn('subscription_id', $user->subscriptions()->select('id'))
                ->where('status', InvoiceStatus::Pending)
                ->lockForUpdate()
                ->get();
            foreach ($open as $invoice) {
                $invoice->forceFill(['status' => InvoiceStatus::Expired, 'due_at' => null])->save();
            }

            return $open->pluck('number')->all();
        });

        ProductEvents::planChanged($user->fresh());

        $creemLine = $running->isEmpty()
            ? 'Creem: nothing to cancel'
            : 'Creem: cancelled now, no charge ('.$running->pluck('creem_subscription_id')->implode(', ').')';
        $this->line("{$email}: {$plan->name} (staff comp) until {$until->toDateString()} · {$creemLine} · invoices voided: ".($voided === [] ? 'none' : implode(', ', $voided)));

        return self::SUCCESS;
    }

    /** The end of --until in the app timezone, when it is after today. */
    private function until(): ?CarbonImmutable
    {
        $raw = (string) $this->option('until');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) !== 1) {
            return null;
        }
        try {
            $day = CarbonImmutable::createFromFormat('!Y-m-d', $raw, config('app.timezone'));
        } catch (Throwable) {
            return null;
        }
        if (! $day instanceof CarbonImmutable || $day->format('Y-m-d') !== $raw || ! $day->isAfter(CarbonImmutable::now(config('app.timezone'))->endOfDay())) {
            return null;
        }

        return $day->endOfDay();
    }

    private function revoke(User $user, string $email): int
    {
        $ended = $user->subscriptions()
            ->where('plan_source', Subscription::SOURCE_STAFF_COMP)
            ->entitled()
            ->update(['status' => SubscriptionStatus::Expired, 'current_period_end' => now()]);
        ProductEvents::planChanged($user->fresh());

        $this->line($ended > 0
            ? "{$email}: staff comp revoked · plan now {$user->fresh()->plan()->name} · Creem: not called"
            : "{$email}: no active staff comp · plan {$user->fresh()->plan()->name} · Creem: not called");

        return self::SUCCESS;
    }
}
