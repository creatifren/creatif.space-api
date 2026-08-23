<?php

namespace App\Models;

use App\Enums\SocialPostStatus;
use App\Enums\SocialPostTargetStatus;
use Carbon\CarbonImmutable;
use Database\Factories\SocialPostFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $ulid
 * @property int $user_id
 * @property string|null $provider_post_id
 * @property string $caption
 * @property array<int, array{url: string}>|null $media
 * @property CarbonImmutable|null $scheduled_at
 * @property SocialPostStatus $status
 * @property string|null $fail_reason
 * @property array<string, mixed>|null $raw_payload
 */
class SocialPost extends Model
{
    /** @use HasFactory<SocialPostFactory> */
    use HasFactory;

    protected $fillable = [
        // Pre-generated in the controller so it can be sent to the provider
        // as external_id before the row exists.
        'ulid',
        'user_id',
        'provider_post_id',
        'caption',
        'media',
        'scheduled_at',
        'status',
        'fail_reason',
        'raw_payload',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'media' => 'array',
            'scheduled_at' => 'datetime',
            'status' => SocialPostStatus::class,
            'raw_payload' => 'array',
        ];
    }

    /**
     * Assign a public ULID on creation.
     */
    protected static function booted(): void
    {
        static::creating(function (self $post): void {
            $post->ulid ??= (string) str()->ulid();
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
     * @return HasMany<SocialPostTarget, $this>
     */
    public function targets(): HasMany
    {
        return $this->hasMany(SocialPostTarget::class);
    }

    /**
     * Fold the per-target results back into one post status, once every
     * target is terminal: any success makes the post Published, none makes
     * it Failed with the first target's reason.
     */
    public function recomputeFromTargets(): void
    {
        $targets = $this->targets()->get();

        if ($targets->isEmpty()
            || $targets->contains(fn (SocialPostTarget $t) => $t->status === SocialPostTargetStatus::Pending)) {
            return;
        }

        $anyPublished = $targets->contains(
            fn (SocialPostTarget $t) => $t->status === SocialPostTargetStatus::Published,
        );

        $this->update($anyPublished
            ? ['status' => SocialPostStatus::Published, 'fail_reason' => null]
            : [
                'status' => SocialPostStatus::Failed,
                'fail_reason' => $targets->firstWhere('fail_reason', '!=', null)?->fail_reason,
            ]);
    }
}
