<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use App\Models\Contracts\WorkspaceOwned;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * One approval request: a set of posts behind one unguessable link. The
 * token is the only credential — whoever holds it can approve, so it is
 * long, random, expiring, and revocable.
 *
 * Since the signed-in approver (Meeting #02) the token only lets the client
 * look: deciding needs a signed-in session whose verified email is
 * approver_email (ApproverGate). Links made before that have none until the
 * first signed-in person to decide binds it (approver_bound_at).
 *
 * @property int $id
 * @property string $ulid
 * @property int $user_id
 * @property string $token
 * @property string $token_hash
 * @property string|null $title
 * @property string|null $client_name
 * @property string|null $approver_email
 * @property CarbonImmutable|null $approver_bound_at
 * @property CarbonImmutable|null $scope_from
 * @property CarbonImmutable|null $scope_to
 * @property string|null $scope_timezone
 * @property CarbonImmutable|null $scope_starts_at
 * @property CarbonImmutable|null $scope_ends_at
 * @property list<int>|null $scope_account_ids
 * @property bool $reminders_enabled Content Plan reminder email on (per-link opt-out)
 * @property CarbonImmutable|null $reminded_at when the one reminder went out
 * @property int $calendar_version bumped when the calendar feed is turned off or rotated (old URLs die)
 * @property CarbonImmutable|null $calendar_disabled_at calendar feed off
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $extended_at
 * @property CarbonImmutable|null $last_reminded_at
 * @property CarbonImmutable|null $revoked_at
 * @property CarbonImmutable|null $created_at
 */
class SocialApprovalLink extends Model implements WorkspaceOwned
{
    protected $fillable = ['user_id', 'token', 'title', 'client_name', 'approver_email', 'approver_bound_at', 'scope_from', 'scope_to', 'scope_timezone', 'scope_starts_at', 'scope_ends_at', 'scope_account_ids', 'reminders_enabled', 'reminded_at', 'calendar_version', 'calendar_disabled_at', 'expires_at', 'extended_at', 'last_reminded_at', 'revoked_at'];

    public function expired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * Open to the client: not revoked, not past its expiry.
     *
     * @param  Builder<self>  $query
     */
    public function scopeLive(Builder $query): void
    {
        $query->whereNull('revoked_at')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    /**
     * Revoked beats expired: a revoked link never comes back, an expired
     * one can be extended.
     */
    public function status(): string
    {
        return $this->revoked_at !== null ? 'revoked' : ($this->expired() ? 'expired' : 'active');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'extended_at' => 'datetime',
            'last_reminded_at' => 'datetime',
            'revoked_at' => 'datetime',
            'approver_bound_at' => 'datetime',
            'scope_from' => 'date',
            'scope_to' => 'date',
            // UTC instants, read back as UTC (UtcDateTime), like scheduled_at.
            'scope_starts_at' => UtcDateTime::class,
            'scope_ends_at' => UtcDateTime::class,
            'scope_account_ids' => 'array',
            'reminders_enabled' => 'boolean',
            'reminded_at' => 'datetime',
            'calendar_version' => 'integer',
            'calendar_disabled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $link): void {
            $link->ulid ??= (string) Str::ulid();
            $link->token ??= Str::random(40);
            // What lookups use: a stolen row cannot open the link.
            $link->token_hash = self::hashToken($link->token);
        });
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /** The link a public URL's token names — looked up by its hash, never the token. */
    public static function findByToken(?string $token): ?self
    {
        if (! is_string($token) || $token === '' || strlen($token) > 255) {
            return null;
        }

        return static::query()->where('token_hash', self::hashToken($token))->first();
    }

    public function workspaceUserId(): ?int
    {
        return $this->user_id;
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
     * @return BelongsToMany<SocialPost, $this>
     */
    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(SocialPost::class, 'social_approval_link_post');
    }

    /**
     * @return HasMany<SocialApprovalDecision, $this>
     */
    public function decisions(): HasMany
    {
        return $this->hasMany(SocialApprovalDecision::class);
    }

    /** Stored lower-cased and trimmed, so lookups and comparisons agree. */
    protected function setApproverEmailAttribute(?string $value): void
    {
        $value = $value === null ? null : mb_strtolower(trim($value));
        $this->attributes['approver_email'] = $value === '' ? null : $value;
    }

    /** A Content Plan (date range + channels) rather than a fixed list of posts. */
    /**
     * Whether the Content Plan reminder may go out. Read from the raw
     * attributes: a link just made in memory has no column default yet.
     */
    public function remindersOn(): bool
    {
        return (bool) ($this->getAttributes()['reminders_enabled'] ?? true);
    }

    public function isScoped(): bool
    {
        return ($this->getAttributes()['scope_from'] ?? null) !== null;
    }

    /**
     * The calendar feed's token: "{ulid}-{signature}", the signature an HMAC
     * of the ulid and calendar_version under the app key. Nothing is stored;
     * bumping the version (off, or a new URL) kills every earlier token.
     */
    public function calendarToken(): string
    {
        return $this->ulid.'-'.self::calendarSignature($this->ulid, $this->calendarVersion());
    }

    public static function calendarSignature(string $ulid, int $version): string
    {
        $mac = hash_hmac('sha256', "content-plan-calendar|{$ulid}|{$version}", (string) config('app.key'), true);

        return rtrim(strtr(base64_encode($mac), '+/', '-_'), '=');
    }

    /** The link a calendar token names, if the signature matches its current version. */
    public static function forCalendarToken(string $token): ?self
    {
        [$ulid, $signature] = array_pad(explode('-', $token, 2), 2, '');
        if ($ulid === '' || $signature === '') {
            return null;
        }
        $link = self::query()->where('ulid', $ulid)->first();

        return $link !== null && hash_equals(self::calendarSignature($ulid, $link->calendarVersion()), $signature) ? $link : null;
    }

    public function calendarOn(): bool
    {
        return ($this->getAttributes()['calendar_disabled_at'] ?? null) === null;
    }

    public function calendarVersion(): int
    {
        return (int) ($this->getAttributes()['calendar_version'] ?? 1);
    }

    public function calendarUrl(): string
    {
        return rtrim((string) config('app.url'), '/').'/api/v1/content-plans/'.$this->calendarToken().'/calendar.ics';
    }

    public function url(): string
    {
        return config('app.public_url').'/a/'.$this->token;
    }
}
