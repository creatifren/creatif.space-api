<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One publish attempt of one destination (see the migration). Written by
 * SocialPostRelay; read by support:post and the retry preview.
 *
 * @property int $id
 * @property int $social_post_id
 * @property int $social_post_target_id
 * @property int $attempt 0 = the first send, then each retry
 * @property string $outcome self::OUTCOMES
 * @property string|null $failure_code PublishFailure reason code
 * @property string|null $reason person-readable
 * @property string|null $provider_post_id
 * @property string $source webhook | reconcile | job | owner
 * @property CarbonImmutable|null $created_at
 */
class PublishAttempt extends Model
{
    protected $table = 'social_post_publish_attempts';

    public const OUTCOMES = ['published', 'failed', 'retrying', 'retry_requested'];

    protected $fillable = [
        'social_post_id', 'social_post_target_id', 'attempt', 'outcome',
        'failure_code', 'reason', 'provider_post_id', 'source',
    ];

    protected function casts(): array
    {
        return [
            'attempt' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * Once per destination, attempt and outcome: a redelivered webhook
     * writes nothing new.
     */
    public static function record(SocialPostTarget $target, string $outcome, string $source, ?string $code = null, ?string $reason = null, ?string $providerPostId = null, ?int $attempt = null): void
    {
        self::query()->firstOrCreate(
            ['social_post_target_id' => $target->id, 'attempt' => $attempt ?? $target->attempts, 'outcome' => $outcome],
            [
                'social_post_id' => $target->social_post_id,
                'failure_code' => $code,
                'reason' => $reason !== null ? Str::limit($reason, 490) : null,
                'provider_post_id' => $providerPostId,
                'source' => $source,
            ],
        );
    }

    /** @return BelongsTo<SocialPostTarget, $this> */
    public function target(): BelongsTo
    {
        return $this->belongsTo(SocialPostTarget::class, 'social_post_target_id');
    }
}
