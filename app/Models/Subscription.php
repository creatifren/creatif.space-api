<?php

namespace App\Models;

use App\Enums\BillingPeriod;
use App\Enums\SubscriptionStatus;
use Carbon\CarbonImmutable;
use Database\Factories\SubscriptionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A paid plan for as long as it is paid for.
 *
 * @property int $id
 * @property string $ulid
 * @property int $user_id
 * @property int $plan_id
 * @property string|null $creem_subscription_id
 * @property string|null $creem_customer_id
 * @property string $provider creem | google
 * @property string|null $google_purchase_token
 * @property string|null $google_purchase_token_hash
 * @property string|null $google_product_id
 * @property string|null $google_order_id
 * @property string|null $google_linked_purchase_token_hash
 * @property bool $google_auto_renewing
 * @property BillingPeriod $billing_period
 * @property SubscriptionStatus $status
 * @property int $seats
 * @property int|null $seats_pending
 * @property int|null $seats_at_renewal
 * @property CarbonImmutable|null $current_period_start
 * @property CarbonImmutable|null $current_period_end
 * @property CarbonImmutable|null $grace_ends_at
 * @property CarbonImmutable|null $cancelled_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class Subscription extends Model
{
    /** @use HasFactory<SubscriptionFactory> */
    use HasFactory;

    /** How long a lapsed subscription keeps its plan before falling to Free. */
    public const GRACE_DAYS = 7;

    public const PROVIDER_CREEM = 'creem';

    public const PROVIDER_GOOGLE = 'google';

    /**
     * The purchase token is a bearer credential for the Play purchase: it
     * never leaves the server.
     *
     * @var list<string>
     */
    protected $hidden = ['google_purchase_token', 'google_purchase_token_hash', 'google_linked_purchase_token_hash'];

    protected $fillable = [
        'plan_id',
        'creem_subscription_id',
        'creem_customer_id',
        'provider',
        'google_purchase_token',
        'google_purchase_token_hash',
        'google_product_id',
        'google_order_id',
        'google_linked_purchase_token_hash',
        'google_auto_renewing',
        'billing_period',
        'status',
        'seats',
        'current_period_start',
        'current_period_end',
        'grace_ends_at',
        'cancelled_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'billing_period' => BillingPeriod::class,
            'status' => SubscriptionStatus::class,
            'seats' => 'integer',
            'google_auto_renewing' => 'boolean',
            'seats_pending' => 'integer',
            'seats_at_renewal' => 'integer',
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'grace_ends_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * Assign a public ULID on creation.
     */
    protected static function booted(): void
    {
        static::creating(function (self $subscription): void {
            $subscription->ulid ??= (string) str()->ulid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * @return HasMany<Invoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class)->latest('id');
    }

    /**
     * Still entitled to the plan: paid up, trialing, or inside the grace
     * window that follows a missed payment.
     */
    public function isEntitled(): bool
    {
        return self::grants($this->status, $this->current_period_end);
    }

    /**
     * Whether a row in $status, paid up to $periodEnd, carries the plan.
     * Cancelled counts until the period ends: Google Play reports a
     * cancelled subscription that still runs that way (Creem keeps such a
     * row Active with cancelled_at set, which the first rule covers).
     */
    public static function grants(SubscriptionStatus $status, ?\DateTimeInterface $periodEnd): bool
    {
        return in_array($status, SubscriptionStatus::entitled(), true)
            || ($status === SubscriptionStatus::Cancelled && $periodEnd !== null && $periodEnd > now());
    }

    /**
     * The rows that carry a plan right now — see grants().
     *
     * @param  Builder<self>  $query
     */
    public function scopeEntitled(Builder $query): void
    {
        $query->where(fn (Builder $q) => $q->whereIn('status', SubscriptionStatus::entitled())
            ->orWhere(fn (Builder $q) => $q->where('status', SubscriptionStatus::Cancelled)
                ->where('current_period_end', '>', now())));
    }

    public function isGoogle(): bool
    {
        return $this->provider === self::PROVIDER_GOOGLE;
    }

    public function isTrialing(): bool
    {
        return $this->status === SubscriptionStatus::Trialing;
    }

    public function isInGrace(): bool
    {
        return $this->status === SubscriptionStatus::PastDue;
    }

    /**
     * Cancelled, but still running until the period they already paid for
     * runs out.
     */
    public function isEndingAtPeriodEnd(): bool
    {
        return $this->cancelled_at !== null
            && (in_array($this->status, [SubscriptionStatus::Active, SubscriptionStatus::Trialing], true)
                || ($this->status === SubscriptionStatus::Cancelled && $this->isEntitled()));
    }
}
