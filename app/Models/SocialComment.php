<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A comment someone left on a post of a connected account (Instagram,
 * Facebook). Not to be confused with SocialPostComment, the approval
 * notes on our own drafts.
 *
 * @property int $id
 * @property string $ulid
 * @property int $user_id
 * @property int $social_account_id
 * @property int|null $social_post_id
 * @property string $provider
 * @property string|null $provider_post_id
 * @property string|null $platform_post_id
 * @property string $comment_id
 * @property string|null $parent_comment_id
 * @property string|null $author_id
 * @property string|null $author_username
 * @property string|null $text
 * @property string $network
 * @property CarbonImmutable|null $commented_at
 * @property CarbonImmutable|null $read_at
 * @property CarbonImmutable|null $replied_at
 * @property CarbonImmutable|null $private_replied_at
 * @property int|null $private_reply_conversation_id
 */
class SocialComment extends Model
{
    /** Instagram allows one private reply per comment, within 7 days of it. */
    public const PRIVATE_REPLY_DAYS = 7;

    protected $fillable = [
        'user_id', 'social_account_id', 'social_post_id', 'provider', 'provider_post_id', 'platform_post_id',
        'comment_id', 'parent_comment_id', 'author_id', 'author_username', 'text', 'network', 'commented_at',
        'read_at', 'replied_at', 'private_replied_at', 'private_reply_conversation_id',
    ];

    protected function casts(): array
    {
        return [
            'commented_at' => 'datetime',
            'read_at' => 'datetime',
            'replied_at' => 'datetime',
            'private_replied_at' => 'datetime',
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

    public function canPrivateReplyUntil(): ?CarbonImmutable
    {
        if ($this->network !== 'instagram' || $this->private_replied_at !== null) {
            return null;
        }

        return CarbonImmutable::parse($this->commented_at ?? $this->created_at)->addDays(self::PRIVATE_REPLY_DAYS);
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

    /** @return BelongsTo<SocialPost, $this> */
    public function post(): BelongsTo
    {
        return $this->belongsTo(SocialPost::class, 'social_post_id');
    }

    /** @return BelongsTo<SocialConversation, $this> */
    public function privateReplyConversation(): BelongsTo
    {
        return $this->belongsTo(SocialConversation::class, 'private_reply_conversation_id');
    }
}
