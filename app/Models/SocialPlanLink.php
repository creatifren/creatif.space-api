<?php

namespace App\Models;

use App\Models\Contracts\WorkspaceOwned;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A public, read-only Content Plan. The token is the only credential, so it
 * is long, random and revocable.
 *
 * @property int $id
 * @property string $ulid
 * @property int $user_id
 * @property string $token
 * @property string $token_hash
 * @property string|null $title
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 * @property list<int>|null $account_ids
 * @property list<string>|null $sections
 * @property CarbonImmutable|null $revoked_at
 * @property CarbonImmutable|null $created_at
 */
class SocialPlanLink extends Model implements WorkspaceOwned
{
    protected $fillable = ['user_id', 'token', 'title', 'starts_at', 'ends_at', 'account_ids', 'sections', 'revoked_at'];

    public const SECTIONS = ['grid', 'calendar', 'feed'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'account_ids' => 'array',
            'sections' => 'array',
            'revoked_at' => 'datetime',
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
        return $this->user_id === null ? null : (int) $this->user_id;
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

    public function url(): string
    {
        return config('app.public_url').'/plan/'.$this->token;
    }
}
