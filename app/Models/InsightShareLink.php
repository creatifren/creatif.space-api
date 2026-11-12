<?php

namespace App\Models;

use App\Models\Contracts\WorkspaceOwned;
use App\Support\Insight\InsightPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A shareable, read-only Insight page (POST /insight/share): one
 * workspace's numbers for one period and set of channels, at
 * {frontend}/report/{token}. The token is only ever stored hashed.
 *
 * @property int $id
 * @property string $ulid
 * @property int $owner_id
 * @property int $user_id
 * @property string $token_hash
 * @property CarbonImmutable $period_from
 * @property CarbonImmutable $period_to
 * @property string $period_kind
 * @property list<string> $channel_ids
 * @property string|null $recipient_email who it was shared with (lowercased), or null for a plain public link
 * @property string|null $recipient_token the token, encrypted (recipient links only), to list it back to them
 * @property CarbonImmutable|null $recipient_notified_at
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $revoked_at
 * @property CarbonImmutable|null $created_at
 */
class InsightShareLink extends Model implements WorkspaceOwned
{
    public const TTL_DAYS = 30;

    protected $fillable = [
        'owner_id', 'user_id', 'token_hash', 'period_from', 'period_to', 'period_kind',
        'channel_ids', 'recipient_email', 'recipient_token', 'recipient_notified_at', 'expires_at', 'revoked_at',
    ];

    /** @var list<string> */
    protected $hidden = ['token_hash', 'recipient_token'];

    /** Set on a link made in this request: the only time the token is known. */
    public ?string $plainToken = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_from' => 'immutable_date',
            'period_to' => 'immutable_date',
            'channel_ids' => 'array',
            'expires_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'recipient_token' => 'encrypted',
            'recipient_notified_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $link): void {
            $link->ulid ??= (string) Str::ulid();
        });
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * A new link for $owner's workspace, made by $by, with its plain token.
     *
     * @param  list<string>  $channelIds
     */
    public static function issue(User $owner, User $by, InsightPeriod $period, array $channelIds, ?string $recipientEmail = null): self
    {
        $token = Str::random(43);
        $recipient = $recipientEmail === null || trim($recipientEmail) === '' ? null : mb_strtolower(trim($recipientEmail));

        $link = self::query()->create([
            'owner_id' => $owner->id,
            'user_id' => $by->id,
            'token_hash' => self::hashToken($token),
            'period_from' => $period->from->toDateString(),
            'period_to' => $period->to->toDateString(),
            'period_kind' => $period->kind,
            'channel_ids' => $channelIds,
            'recipient_email' => $recipient,
            // Only a recipient link can be listed back, so only it keeps the token (encrypted).
            'recipient_token' => $recipient === null ? null : $token,
            'expires_at' => now()->addDays(self::TTL_DAYS),
        ]);
        $link->plainToken = $token;

        return $link;
    }

    public static function findByToken(?string $token): ?self
    {
        return $token === null || $token === '' ? null
            : self::query()->where('token_hash', self::hashToken($token))->first();
    }

    public function workspaceUserId(): ?int
    {
        return $this->owner_id;
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    public function expired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function revoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function period(): InsightPeriod
    {
        return InsightPeriod::fromDates($this->period_from, $this->period_to, $this->period_kind);
    }

    /** The page URL for the recipient, from the encrypted token; null for a plain link. */
    public function recipientUrl(): ?string
    {
        $token = $this->recipient_email === null ? null : $this->recipient_token;

        return $token === null ? null : self::url($token);
    }

    /** The public page in the app. */
    public static function url(string $token): string
    {
        return rtrim((string) config('app.frontend_url'), '/').'/report/'.$token;
    }

    /**
     * Not revoked, not expired.
     *
     * @param  Builder<self>  $query
     */
    public function scopeLive(Builder $query): void
    {
        $query->whereNull('revoked_at')->where('expires_at', '>', now());
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
}
