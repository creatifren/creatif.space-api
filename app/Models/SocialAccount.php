<?php

namespace App\Models;

use App\Enums\SocialAccountStatus;
use App\Enums\SocialPlatform;
use Carbon\CarbonImmutable;
use Database\Factories\SocialAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A social account connected through a relay (Outstand, or Post for Me before it). We hold only the mapping
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
 * @property int|null $followers_count
 * @property CarbonImmutable|null $followers_synced_at
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
        'followers_count',
        'followers_synced_at',
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
            'followers_count' => 'integer',
            'followers_synced_at' => 'datetime',
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

    /** The relay new posts go through (`SOCIAL_PROVIDER`). */
    public static function currentProvider(): string
    {
        return (string) config('services.social.provider');
    }

    /**
     * The one meaning of "usable": Connected, and held by the current
     * relay. A row another relay holds (Post for Me before the Outstand
     * switch) cannot be posted through this one — its ids mean nothing
     * here — so it neither counts toward the plan nor goes in a post.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function usable(Builder $query): void
    {
        $query->where('status', SocialAccountStatus::Connected)
            ->where('provider', self::currentProvider());
    }

    public function isUsable(): bool
    {
        return $this->status === SocialAccountStatus::Connected
            && $this->provider === self::currentProvider();
    }

    /** Connected under another relay: the user has to reconnect it. */
    public function isLegacy(): bool
    {
        return $this->status === SocialAccountStatus::Connected
            && $this->provider !== self::currentProvider();
    }

    /** How it is named to the user: "instagram @handle". */
    public function label(): string
    {
        return $this->platform->value.($this->username ? ' @'.ltrim($this->username, '@') : '');
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
            && $this->isUsable()
            && $this->messages_scope_at !== null;
    }

    /** Connected before the Inbox (or without the scope): reconnect to read DMs. */
    public function needsReconnectForMessages(): bool
    {
        return (bool) config('services.inbox.enabled')
            && $this->supportsMessages()
            && $this->isUsable()
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
