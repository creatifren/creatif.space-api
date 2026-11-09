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
 * @property string $provider
 * @property SocialPlatform $platform
 * @property string $provider_account_id
 * @property string|null $username
 * @property string|null $profile_photo_url
 * @property SocialAccountStatus $status
 * @property CarbonImmutable|null $connected_at
 * @property CarbonImmutable|null $messages_scope_at
 * @property CarbonImmutable|null $inbox_synced_at
 */
class SocialAccount extends Model
{
    /** @use HasFactory<SocialAccountFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'provider',
        'platform',
        'provider_account_id',
        'username',
        'profile_photo_url',
        'status',
        'connected_at',
        'messages_scope_at',
        'inbox_synced_at',
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
            'messages_scope_at' => 'datetime',
            'inbox_synced_at' => 'datetime',
        ];
    }

    /**
     * Assign a public ULID on creation.
     */
    protected static function booted(): void
    {
        static::creating(function (self $account): void {
            $account->ulid ??= (string) str()->ulid();
            // Which relay holds it; ids mean nothing to the other one.
            $account->provider ??= (string) config('services.social.provider');
        });
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** DMs go through Outstand, on Instagram only (Oct 2026). */
    public function supportsMessages(): bool
    {
        return $this->provider === 'outstand' && $this->platform === SocialPlatform::Instagram;
    }

    /** Comments are read on Instagram and Facebook. */
    public function supportsComments(): bool
    {
        return $this->provider === 'outstand'
            && in_array($this->platform, [SocialPlatform::Instagram, SocialPlatform::Facebook], true);
    }

    /** Connected with the messages scope: the Inbox can read and send DMs. */
    public function messagingEnabled(): bool
    {
        return (bool) config('services.inbox.enabled')
            && $this->supportsMessages()
            && $this->status === SocialAccountStatus::Connected
            && $this->messages_scope_at !== null;
    }

    /** Connected before the Inbox (or without the scope): reconnect to read DMs. */
    public function needsReconnectForMessages(): bool
    {
        return (bool) config('services.inbox.enabled')
            && $this->supportsMessages()
            && $this->status === SocialAccountStatus::Connected
            && $this->messages_scope_at === null;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
