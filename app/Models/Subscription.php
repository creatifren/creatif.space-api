<?php

namespace App\Models;

use App\Enums\BillingPeriod;
use App\Enums\SubscriptionStatus;
use Carbon\CarbonImmutable;
use Database\Factories\SubscriptionFactory;
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

    protected $fillable = [
        'plan_id',
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
     * Still entitled to the plan: paid up, or inside the grace window that
     * follows a missed payment.
     */
    public function isEntitled(): bool
    {
        return in_array(
            $this->status,
            [SubscriptionStatus::Active, SubscriptionStatus::PastDue],
            true,
        );
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
            && $this->status === SubscriptionStatus::Active;
    }
}
