<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One DM. Ours start `pending` (Outstand answers 202) and turn `sent` or
 * `failed` on the message.sent / message.failed webhook.
 *
 * @property int $id
 * @property string $ulid
 * @property int $social_conversation_id
 * @property string|null $provider_message_id
 * @property string|null $platform_message_id
 * @property string $direction
 * @property string|null $content
 * @property list<string>|null $media_urls
 * @property string $status
 * @property string|null $error
 * @property string|null $source
 * @property CarbonImmutable|null $scheduled_at
 * @property CarbonImmutable|null $sent_at
 * @property CarbonImmutable|null $read_at
 */
class SocialMessage extends Model
{
    public const IN = 'in';

    public const OUT = 'out';

    public const PENDING = 'pending';

    public const SENT = 'sent';

    public const FAILED = 'failed';

    protected $fillable = [
        'social_conversation_id', 'provider_message_id', 'platform_message_id', 'direction', 'content',
        'media_urls', 'status', 'error', 'source', 'scheduled_at', 'sent_at', 'read_at',
    ];

    protected function casts(): array
    {
        return [
            'media_urls' => 'array',
            'scheduled_at' => 'datetime',
            'sent_at' => 'datetime',
            'read_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $m): void {
            $m->ulid ??= (string) str()->ulid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** @return BelongsTo<SocialConversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(SocialConversation::class, 'social_conversation_id');
    }
}
