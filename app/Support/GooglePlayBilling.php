<?php

namespace App\Support;

use App\Enums\SubscriptionStatus;
use App\Exceptions\GooglePlayRefused;
use App\Exceptions\GooglePlayUnavailable;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\GooglePlayClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Google Play subscriptions on our Subscription rows.
 *
 * Google is the source of truth: the app's verify call and every real-time
 * developer notification re-read purchases.subscriptionsv2 and run it
 * through the same mapping here.
 *
 *   ACTIVE                → Active
 *   IN_GRACE_PERIOD       → Active, grace_ends_at = expiryTime
 *   ON_HOLD, PAUSED       → PastDue (the usual grace window, then Expired)
 *   CANCELED              → Cancelled, entitled until expiryTime
 *   EXPIRED               → Expired
 *   PENDING               → nothing granted (the caller answers 202)
 *
 * A row is keyed by sha256(purchase token); the same token is the same row.
 * A purchase that replaced another (linkedPurchaseToken: upgrade,
 * downgrade, resubscribe) ends the old row instead of standing beside it.
 */
class GooglePlayBilling
{
    public const PENDING = 'PENDING';

    public function __construct(private GooglePlayClient $client) {}

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * The plan and period a Play product sells, or null when it is not ours.
     *
     * @return array{plan: string, period: string}|null
     */
    public static function product(mixed $productId): ?array
    {
        if (! is_string($productId) || $productId === '') {
            return null;
        }
        $entry = config('services.google_play.products', [])[$productId] ?? null;

        return is_array($entry) && is_string($entry['plan'] ?? null) && in_array($entry['period'] ?? null, ['monthly', 'yearly'], true)
            ? ['plan' => $entry['plan'], 'period' => $entry['period']]
            : null;
    }

    /**
     * subscriptionState without its SUBSCRIPTION_STATE_ prefix.
     *
     * @param  array<string, mixed>  $remote
     */
    public static function state(array $remote): string
    {
        return str_replace('SUBSCRIPTION_STATE_', '', (string) ($remote['subscriptionState'] ?? ''));
    }

    /**
     * The line item for $productId, or — no product named — the first line
     * item that sells one of our plans.
     *
     * @param  array<string, mixed>  $remote
     * @return array<string, mixed>|null
     */
    public static function lineItem(array $remote, ?string $productId = null): ?array
    {
        foreach (is_array($remote['lineItems'] ?? null) ? $remote['lineItems'] : [] as $item) {
            if (! is_array($item) || self::product($item['productId'] ?? null) === null) {
                continue;
            }
            if ($productId === null || ($item['productId'] ?? null) === $productId) {
                return $item;
            }
        }

        return null;
    }

    /**
     * Google's RFC 3339 time ("…Z") in the app's zone: subscriptions'
     * `datetime` columns hold that zone's wall clock.
     */
    private static function time(mixed $value): ?CarbonImmutable
    {
        return is_string($value) && $value !== ''
            ? CarbonImmutable::parse($value)->setTimezone((string) config('app.timezone'))
            : null;
    }

    /** @param  array<string, mixed>  $remote */
    public static function accountId(array $remote): ?string
    {
        $id = $remote['externalAccountIdentifiers']['obfuscatedExternalAccountId'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * Where the row lands for this Google state, before anything is written.
     *
     * @param  array<string, mixed>  $lineItem
     * @return array{status: SubscriptionStatus, period_end: CarbonImmutable|null, grace_ends_at: CarbonImmutable|null}|null null = a state we do not map
     */
    public static function target(string $state, array $lineItem, ?Subscription $row): ?array
    {
        $expiry = self::time($lineItem['expiryTime'] ?? null);

        return match ($state) {
            'ACTIVE' => ['status' => SubscriptionStatus::Active, 'period_end' => $expiry, 'grace_ends_at' => null],
            'IN_GRACE_PERIOD' => ['status' => SubscriptionStatus::Active, 'period_end' => $expiry, 'grace_ends_at' => $expiry],
            // A fixed window, as with Creem's past_due: Google keeps retrying
            // the card meanwhile; the expiry job ends access once it closes.
            'ON_HOLD', 'PAUSED' => ['status' => SubscriptionStatus::PastDue, 'period_end' => $expiry,
                'grace_ends_at' => $row !== null && $row->status === SubscriptionStatus::PastDue && $row->grace_ends_at !== null
                    ? $row->grace_ends_at->toImmutable()
                    : now()->toImmutable()->addDays(Subscription::GRACE_DAYS)],
            'CANCELED' => ['status' => $expiry !== null && $expiry->isFuture() ? SubscriptionStatus::Cancelled : SubscriptionStatus::Expired,
                'period_end' => $expiry, 'grace_ends_at' => null],
            'EXPIRED', 'PENDING_PURCHASE_CANCELED' => ['status' => SubscriptionStatus::Expired, 'period_end' => $expiry, 'grace_ends_at' => null],
            default => null,
        };
    }

    /**
     * Write what Google says onto the row for $token (creating it when
     * new), end the row it replaced, and fire the same product events as a
     * Creem subscription. Runs in the caller's transaction.
     *
     * @param  array<string, mixed>  $remote  subscriptionsv2
     * @param  array<string, mixed>  $lineItem  one of $remote's lineItems, a product of ours
     * @param  array{status: SubscriptionStatus, period_end: CarbonImmutable|null, grace_ends_at: CarbonImmutable|null}  $target
     */
    public static function apply(User $user, ?Subscription $row, string $token, array $remote, array $lineItem, array $target): Subscription
    {
        $hash = self::hash($token);
        $product = self::product($lineItem['productId'] ?? null) ?? throw GooglePlayRefused::unknownProduct();
        $plan = Plan::query()->where('key', $product['plan'])->firstOrFail();
        $planBefore = ProductEvents::planOf($user);

        $linked = is_string($remote['linkedPurchaseToken'] ?? null) && $remote['linkedPurchaseToken'] !== ''
            ? self::hash($remote['linkedPurchaseToken'])
            : null;
        $status = $target['status'];
        // A token a newer purchase replaced never comes back to life.
        if (Subscription::query()->where('google_linked_purchase_token_hash', $hash)->where('user_id', $user->id)->exists()) {
            $status = SubscriptionStatus::Expired;
        }

        $created = $row === null;
        $wasEntitled = $row?->isEntitled() ?? false;
        $wasCancelled = $row?->cancelled_at !== null;
        $row ??= new Subscription(['seats' => 1]);
        $row->user_id = $user->id;

        $periodEnd = $target['period_end'];
        $periodStart = $row->current_period_start;
        if ($created) {
            $periodStart = self::time($remote['startTime'] ?? null) ?? now();
        } elseif ($periodEnd !== null && $row->current_period_end !== null && $periodEnd->gt($row->current_period_end)
            && $target['status'] === SubscriptionStatus::Active) {
            // Renewed: the new period starts where the last one ended.
            $periodStart = $row->current_period_end;
        }

        $cancelledAt = match (true) {
            $target['status'] === SubscriptionStatus::Cancelled,
            $target['status'] === SubscriptionStatus::Expired && self::state($remote) === 'CANCELED' => $row->cancelled_at ?? now(),
            $target['status'] === SubscriptionStatus::Active => null,
            default => $row->cancelled_at,
        };

        $row->fill([
            'plan_id' => $plan->id,
            'provider' => Subscription::PROVIDER_GOOGLE,
            'billing_period' => $product['period'],
            'status' => $status,
            'google_purchase_token' => $token,
            'google_purchase_token_hash' => $hash,
            'google_product_id' => $lineItem['productId'],
            'google_order_id' => is_string($remote['latestOrderId'] ?? null) ? $remote['latestOrderId'] : $row->google_order_id,
            'google_linked_purchase_token_hash' => $linked ?? $row->google_linked_purchase_token_hash,
            'google_auto_renewing' => (bool) ($lineItem['autoRenewingPlan']['autoRenewEnabled'] ?? false),
            'current_period_start' => $periodStart,
            'current_period_end' => $periodEnd,
            'grace_ends_at' => $status === SubscriptionStatus::Expired ? null : $target['grace_ends_at'],
            'cancelled_at' => $cancelledAt,
        ]);
        $row->save();

        // The purchase this one replaced ends now; a plan that was running
        // there makes this a change of plan, not a new subscription.
        $replacedRunning = false;
        if ($linked !== null) {
            $old = Subscription::query()
                ->where('google_purchase_token_hash', $linked)
                ->where('user_id', $user->id)
                ->whereKeyNot($row->id);
            $replacedRunning = (clone $old)->entitled()->exists();
            $old->where('status', '!=', SubscriptionStatus::Expired)
                ->update(['status' => SubscriptionStatus::Expired, 'grace_ends_at' => null]);
        }

        $row->setRelation('plan', $plan);
        $entitled = $row->isEntitled();
        // Once per purchase, as Creem's first paid event: not on a replay,
        // and not for an upgrade/downgrade of a plan already running.
        if ($entitled && ! $wasEntitled && $created && ! $replacedRunning) {
            ProductEvents::subscriptionStarted($user, $plan->key, $product['period'], (int) $plan->priceFor($product['period']), Subscription::PROVIDER_GOOGLE);
        }
        if ($row->cancelled_at !== null && ! $wasCancelled && ! $created) {
            ProductEvents::subscriptionCanceled($user, $plan->key);
        }
        ProductEvents::planChanged($user, $planBefore);

        return $row;
    }

    /**
     * Acknowledge the purchase if Google still waits for it. A failure is
     * left for the next verify or notification: Google keeps saying
     * PENDING until one of them gets through (three days before refund).
     *
     * @param  array<string, mixed>  $remote
     */
    public function acknowledgeIfPending(Subscription $row, array $remote): void
    {
        if (($remote['acknowledgementState'] ?? null) !== 'ACKNOWLEDGEMENT_STATE_PENDING'
            || ! $row->isEntitled()
            || $row->google_product_id === null || $row->google_purchase_token === null) {
            return;
        }

        try {
            $this->client->acknowledge($row->google_product_id, $row->google_purchase_token);
        } catch (GooglePlayUnavailable $e) {
            Log::warning('google_play.acknowledge_failed', ['subscription' => $row->ulid, 'reason' => $e->getMessage()]);
        }
    }

    /**
     * A notification about $token: re-read it from Google and apply. An
     * unknown token is left alone — the app's verify call creates the row.
     *
     * @return string what happened, for the log
     *
     * @throws GooglePlayUnavailable
     */
    public function sync(string $token, bool $revoked = false): string
    {
        $row = Subscription::query()->where('google_purchase_token_hash', self::hash($token))->first();
        if ($row === null) {
            return 'unknown_token';
        }

        try {
            $remote = $this->client->getSubscriptionV2($token);
        } catch (GooglePlayRefused) {
            // Google no longer knows the token: nothing left to entitle.
            $this->expire($row);

            return 'gone';
        }

        $user = $row->user;
        $account = self::accountId($remote);
        if ($account !== null && $account !== $user->ulid) {
            Log::warning('google_play.rtdn_account_mismatch', ['subscription' => $row->ulid]);

            return 'account_mismatch';
        }

        $lineItem = self::lineItem($remote, $row->google_product_id) ?? self::lineItem($remote);
        $target = $lineItem === null ? null : self::target(self::state($remote), $lineItem, $row);
        if ($lineItem === null || $target === null) {
            if ($revoked) {
                $this->expire($row);
            }

            return 'unmapped';
        }

        $row = DB::transaction(function () use ($user, $row, $token, $remote, $lineItem, $target, $revoked) {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $locked = Subscription::query()->whereKey($row->id)->lockForUpdate()->firstOrFail();
            // A refund ends the plan now, whatever the period says.
            if ($revoked) {
                $target = ['status' => SubscriptionStatus::Expired, 'period_end' => $target['period_end'], 'grace_ends_at' => null];
            }

            return self::apply($user, $locked, $token, $remote, $lineItem, $target);
        });

        $this->acknowledgeIfPending($row, $remote);

        return 'applied';
    }

    /** The plan ends now: a refund, a voided purchase, a token Google dropped. */
    public function expire(Subscription $row): void
    {
        $user = $row->user;
        $before = ProductEvents::planOf($user);
        $row->forceFill(['status' => SubscriptionStatus::Expired, 'grace_ends_at' => null])->save();
        ProductEvents::planChanged($user, $before);
    }
}
