<?php

namespace App\Models;

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
 * @property string|null $title
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 * @property list<int>|null $account_ids
 * @property list<string>|null $sections
 * @property CarbonImmutable|null $revoked_at
 * @property CarbonImmutable|null $created_at
 */
class SocialPlanLink extends Model
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

    public function url(): string
    {
        return config('app.public_url').'/plan/'.$this->token;
    }
}
