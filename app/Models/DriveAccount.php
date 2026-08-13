<?php

namespace App\Models;

use App\Enums\DriveAccountStatus;
use Carbon\CarbonImmutable;
use Database\Factories\DriveAccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $ulid
 * @property int $user_id
 * @property string $provider
 * @property string $provider_account_id
 * @property string $email
 * @property string|null $access_token
 * @property string|null $refresh_token
 * @property CarbonImmutable|null $token_expires_at
 * @property DriveAccountStatus $status
 * @property CarbonImmutable|null $last_synced_at
 */
class DriveAccount extends Model
{
    /** @use HasFactory<DriveAccountFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'provider',
        'provider_account_id',
        'email',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'status',
    ];

    /**
     * Tokens never leave the server: encrypted at rest, hidden from arrays.
     *
     * @var list<string>
     */
    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'status' => DriveAccountStatus::class,
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

    /**
     * @return HasMany<DriveFile, $this>
     */
    public function files(): HasMany
    {
        return $this->hasMany(DriveFile::class);
    }
}
