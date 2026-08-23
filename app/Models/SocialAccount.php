<?php

namespace App\Models;

use App\Enums\SocialAccountStatus;
use App\Enums\SocialPlatform;
use Carbon\CarbonImmutable;
use Database\Factories\SocialAccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A social account connected through Post for Me. We hold only the mapping
 * from our user to the provider's account id — the OAuth tokens live with
 * the provider and never touch this table.
 *
 * @property int $id
 * @property string $ulid
 * @property int $user_id
 * @property SocialPlatform $platform
 * @property string $provider_account_id
 * @property string|null $username
 * @property string|null $profile_photo_url
 * @property SocialAccountStatus $status
 * @property CarbonImmutable|null $connected_at
 */
class SocialAccount extends Model
{
    /** @use HasFactory<SocialAccountFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'platform',
        'provider_account_id',
        'username',
        'profile_photo_url',
        'status',
        'connected_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'platform' => SocialPlatform::class,
            'status' => SocialAccountStatus::class,
            'connected_at' => 'datetime',
        ];
    }

    /**
     * Assign a public ULID on creation.
     */
    protected static function booted(): void
    {
        static::creating(function (self $account): void {
            $account->ulid ??= (string) str()->ulid();
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
}
