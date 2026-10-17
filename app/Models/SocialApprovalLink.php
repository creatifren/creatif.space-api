<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

/**
 * One approval request: a set of posts behind one unguessable link. The
 * token is the only credential — whoever holds it can approve, so it is
 * long, random, expiring, and revocable.
 *
 * @property int $id
 * @property string $ulid
 * @property int $user_id
 * @property string $token
 * @property string|null $title
 * @property string|null $client_name
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $revoked_at
 * @property CarbonImmutable|null $created_at
 */
class SocialApprovalLink extends Model
{
    protected $fillable = ['user_id', 'token', 'title', 'client_name', 'expires_at', 'revoked_at'];

    public function expired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $link): void {
            $link->ulid ??= (string) Str::ulid();
            $link->token ??= Str::random(40);
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
     * @return BelongsToMany<SocialPost, $this>
     */
    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(SocialPost::class, 'social_approval_link_post');
    }

    public function url(): string
    {
        return config('app.public_url').'/a/'.$this->token;
    }
}
