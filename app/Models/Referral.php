<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ReferralFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One person's journey from a click to a paying account.
 *
 * @property int $id
 * @property int $affiliate_id
 * @property int|null $referred_user_id
 * @property CarbonImmutable|null $clicked_at
 * @property CarbonImmutable|null $signed_up_at
 * @property CarbonImmutable|null $first_paid_at
 * @property CarbonImmutable $cookie_expires_at
 * @property bool $discount_applied
 * @property CarbonImmutable|null $created_at
 * @property-read Affiliate $affiliate
 */
class Referral extends Model
{
    /** @use HasFactory<ReferralFactory> */
    use HasFactory;

    protected $fillable = [
        'affiliate_id',
        'referred_user_id',
        'clicked_at',
        'signed_up_at',
        'cookie_expires_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'clicked_at' => 'datetime',
            'signed_up_at' => 'datetime',
            'first_paid_at' => 'datetime',
            'cookie_expires_at' => 'datetime',
            'discount_applied' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Affiliate, $this>
     */
    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function referredUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_user_id');
    }

    /**
     * @return HasMany<Commission, $this>
     */
    public function commissions(): HasMany
    {
        return $this->hasMany(Commission::class);
    }

    /**
     * Is this referral still inside the twelve months it earns for? Before
     * the first payment the answer is yes — the window has not started.
     */
    public function isEarning(): bool
    {
        return $this->first_paid_at === null
            || $this->first_paid_at->addMonths(Affiliate::EARNING_MONTHS)->isFuture();
    }
}
