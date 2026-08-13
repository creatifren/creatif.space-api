<?php

namespace App\Models;

use App\Enums\AffiliateStatus;
use Carbon\CarbonImmutable;
use Database\Factories\AffiliateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Someone who refers people, and the rate they earn at.
 *
 * @property int $id
 * @property string $ulid
 * @property int $user_id
 * @property AffiliateStatus $status
 * @property string $code
 * @property string $tier_percent
 * @property int $paid_referrals_count
 * @property array<string, mixed> $application
 * @property CarbonImmutable|null $approved_at
 * @property string|null $rejection_reason
 * @property CarbonImmutable|null $created_at
 * @property-read User $user
 */
class Affiliate extends Model
{
    /** @use HasFactory<AffiliateFactory> */
    use HasFactory;

    /** The rate everyone starts at, in percent. */
    public const TIER_BASE = 20;

    /** Paid referrals needed for 25%, then for 30%. */
    public const TIER_2_AT = 10;

    public const TIER_3_AT = 25;

    /** How long one referred customer keeps earning: twelve payments. */
    public const EARNING_MONTHS = 12;

    /** Commission is paid in twelve monthly parts. */
    public const INSTALMENTS = 12;

    /** Each part is held this long before it can be withdrawn. */
    public const HOLD_DAYS = 30;

    /** The referral cookie's life — it governs the signup, not the money. */
    public const COOKIE_DAYS = 90;

    protected $fillable = [
        'user_id',
        'code',
        'application',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AffiliateStatus::class,
            'application' => 'array',
            'paid_referrals_count' => 'integer',
            'approved_at' => 'datetime',
        ];
    }

    /**
     * Assign a public ULID on creation.
     */
    protected static function booted(): void
    {
        static::creating(function (self $affiliate): void {
            $affiliate->ulid ??= (string) str()->ulid();
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
     * @return HasMany<Referral, $this>
     */
    public function referrals(): HasMany
    {
        return $this->hasMany(Referral::class);
    }

    public function isApproved(): bool
    {
        return $this->status === AffiliateStatus::Approved;
    }

    /**
     * The rate this many paid referrals earns. Moves down as well as up —
     * the program page promises that refunds pulling the count back under
     * a threshold take the tier with them, so this is a plain assignment
     * rather than a high-water mark.
     */
    public static function tierFor(int $paidReferrals): int
    {
        return match (true) {
            $paidReferrals >= self::TIER_3_AT => 30,
            $paidReferrals >= self::TIER_2_AT => 25,
            default => self::TIER_BASE,
        };
    }

    /**
     * How many more paid referrals until the next rate, and what it is.
     * Null once there is nothing left to climb.
     *
     * @return array{percent: int, needed: int}|null
     */
    public function nextTier(): ?array
    {
        $count = $this->paid_referrals_count;

        return match (true) {
            $count < self::TIER_2_AT => ['percent' => 25, 'needed' => self::TIER_2_AT - $count],
            $count < self::TIER_3_AT => ['percent' => 30, 'needed' => self::TIER_3_AT - $count],
            default => null,
        };
    }

    /**
     * A referral code from the person's handle, uniquified. Readable, so
     * it can be said out loud — that is the whole point of a referral link.
     */
    public static function generateCode(User $user): string
    {
        $base = Str::upper(Str::limit(
            Str::slug($user->handle === null ? $user->name : $user->handle->name, ''),
            12,
            '',
        )) ?: 'CREATIF';

        $code = $base;
        $n = 1;

        while (static::query()->where('code', $code)->exists()) {
            $code = $base.(++$n);
        }

        return $code;
    }
}
