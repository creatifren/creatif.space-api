<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Whoever approves. Authenticatable because approving requires signing in
 * with Google — but on the `client` guard, with no dashboard behind it.
 *
 * @property int $id
 * @property string $ulid
 * @property string|null $google_id
 * @property string $email
 * @property string|null $name
 * @property string|null $avatar_url
 * @property int|null $user_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class Client extends Authenticatable
{
    /** @use HasFactory<ClientFactory> */
    use HasFactory;

    protected $fillable = [
        'google_id',
        'email',
        'name',
        'avatar_url',
        'user_id',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'remember_token',
    ];

    /**
     * Assign a public ULID on creation.
     */
    protected static function booted(): void
    {
        static::creating(function (self $client): void {
            $client->ulid ??= (string) str()->ulid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /**
     * The creator account behind this email, when there is one. Set at
     * login so Insights can show the decisions this person gave elsewhere.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<Approval, $this>
     */
    public function approvals(): HasMany
    {
        return $this->hasMany(Approval::class);
    }

    /**
     * What this person bought — the buyer's side of the till.
     *
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * The name to show when the client never gave one — an email's local
     * part is a better label than a blank space.
     */
    public function displayName(): string
    {
        return $this->name ?? str($this->email)->before('@')->toString();
    }
}
