<?php

namespace App\Models;

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
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $extended_at
 * @property CarbonImmutable|null $revoked_at
 * @property CarbonImmutable|null $created_at
 */
class SocialApprovalLink extends Model
{
    protected $fillable = ['user_id', 'token', 'title', 'client_name', 'approver_email', 'approver_bound_at', 'expires_at', 'extended_at', 'revoked_at'];

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
            'revoked_at' => 'datetime',
            'approver_bound_at' => 'datetime',
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

    public function url(): string
    {
        return config('app.public_url').'/a/'.$this->token;
    }
}
