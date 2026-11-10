<?php

namespace App\Models;

use App\Enums\SocialPostTargetStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One destination of a post. The provider reports success or failure per
 * social account, and the UI shows the reason per platform — hence a row,
 * not a JSON column.
 *
 * @property int $id
 * @property int $social_post_id
 * @property int $social_account_id
 * @property array<string, mixed>|null $configuration
 * @property SocialPostTargetStatus $status
 * @property string|null $fail_reason person-readable (PublishFailure::reason)
 * @property string|null $fail_code PublishFailure reason code
 * @property string|null $provider_error the provider's raw text — server-side only
 * @property int $attempts provider attempts after the first (rate-limit retries)
 * @property CarbonImmutable|null $retry_at
 * @property string|null $retry_provider_post_id the provider post carrying the latest retry
 * @property string|null $platform_url
 * @property string|null $provider_result_id
 * @property string|null $paid_with self::MONTHLY (some or all from Monthly Credits) or self::CREDITS (Top-up only); self::ALLOWANCE on legacy rows; null = not charged
 * @property int|null $charged_credits Top-up Credits this destination paid; null on legacy rows (a CREDITS one = 300)
 * @property int|null $charged_monthly Monthly Credits this destination paid
 * @property CarbonImmutable|null $charged_at
 */
class SocialPostTarget extends Model
{
    /** Legacy: the count-based allowance, before Monthly Credits. */
    public const ALLOWANCE = 'allowance';

    /** Paid from Top-up Credits only. */
    public const CREDITS = 'credits';

    /** Paid at least partly from Monthly Credits (charged_monthly), rest Top-up. */
    public const MONTHLY = 'monthly';

    /** Raw provider text never leaves the server. */
    protected $hidden = ['provider_error'];

    protected $fillable = [
        'social_post_id',
        'social_account_id',
        'configuration',
        'status',
        'fail_reason',
        'fail_code',
        'provider_error',
        'attempts',
        'retry_at',
        'retry_provider_post_id',
        'platform_url',
        'provider_result_id',
        'paid_with',
        'charged_credits',
        'charged_monthly',
        'charged_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'configuration' => 'array',
            'status' => SocialPostTargetStatus::class,
            'charged_credits' => 'integer',
            'charged_monthly' => 'integer',
            'charged_at' => 'datetime',
            'attempts' => 'integer',
            'retry_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<SocialPost, $this>
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(SocialPost::class, 'social_post_id');
    }

    /**
     * @return BelongsTo<SocialAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class, 'social_account_id');
    }
}
