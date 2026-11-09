<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A DM thread on a connected account (Instagram only, via Outstand).
 *
 * @property int $id
 * @property string $ulid
 * @property int $user_id
 * @property int $social_account_id
 * @property string $provider
 * @property string $provider_conversation_id
 * @property string $network
 * @property string|null $contact_id
 * @property string|null $contact_username
 * @property string|null $contact_name
 * @property string|null $contact_avatar_url
 * @property string|null $last_message_preview
 * @property CarbonImmutable|null $last_message_at
 * @property CarbonImmutable|null $last_inbound_at
 * @property int $unread_count
 * @property string $status
 * @property CarbonImmutable|null $messages_synced_at
 */
class SocialConversation extends Model
{
    public const OPEN = 'open';

    public const DONE = 'done';

    /** Instagram's window for a free-form reply after the contact's last message. */
    public const REPLY_WINDOW_HOURS = 24;

    protected $fillable = [
        'user_id', 'social_account_id', 'provider', 'provider_conversation_id', 'network',
        'contact_id', 'contact_username', 'contact_name', 'contact_avatar_url',
        'last_message_preview', 'last_message_at', 'last_inbound_at', 'unread_count', 'status',
        'messages_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
            'last_inbound_at' => 'datetime',
            'messages_synced_at' => 'datetime',
            'unread_count' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $c): void {
            $c->ulid ??= (string) str()->ulid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** Until when a free-form reply is allowed; null when the contact never wrote. */
    public function canReplyUntil(): ?CarbonImmutable
    {
        return $this->last_inbound_at === null
            ? null
            : CarbonImmutable::parse($this->last_inbound_at)->addHours(self::REPLY_WINDOW_HOURS);
    }

    public function canReply(): bool
    {
        $until = $this->canReplyUntil();

        return $until !== null && $until->isFuture();
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<SocialAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class, 'social_account_id');
    }

    /** @return HasMany<SocialMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(SocialMessage::class);
    }
}
