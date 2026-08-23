<?php

namespace App\Models;

use App\Enums\SocialPostTargetStatus;
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
 * @property string|null $fail_reason
 * @property string|null $platform_url
 * @property string|null $provider_result_id
 */
class SocialPostTarget extends Model
{
    protected $fillable = [
        'social_post_id',
        'social_account_id',
        'configuration',
        'status',
        'fail_reason',
        'platform_url',
        'provider_result_id',
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
