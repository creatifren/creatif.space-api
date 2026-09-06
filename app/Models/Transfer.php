<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Files going out, as a link. File Request in reverse.
 *
 * The transfer points at library rows rather than copying them, so sending
 * the same shoot to three clients costs one set of bytes and deleting a
 * transfer never removes a file.
 *
 * @property int $id
 * @property string $ulid
 * @property int $user_id
 * @property string $slug
 * @property string $title
 * @property string|null $note
 * @property string|null $password_hash
 * @property \Carbon\CarbonImmutable|null $expires_at
 * @property int $opens
 * @property int $downloads
 */
class Transfer extends Model
{
    /** @use HasFactory<\Database\Factories\TransferFactory> */
    use HasFactory;

    /** How long a link lives when the sender names no date. */
    public const DEFAULT_DAYS = 7;

    protected $fillable = ['title', 'note', 'expires_at'];

    /** Never serialised: the hash is the sender's secret, not the page's. */
    protected $hidden = ['password_hash'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $transfer): void {
            $transfer->ulid ??= (string) str()->ulid();
            $transfer->slug ??= static::generateSlug();
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
     * @return BelongsToMany<File, $this>
     */
    public function files(): BelongsToMany
    {
        return $this->belongsToMany(File::class, 'transfer_files')
            ->withPivot('sort_order')
            ->orderBy('transfer_files.sort_order');
    }

    /**
     * @return HasMany<TransferRecipient, $this>
     */
    public function recipients(): HasMany
    {
        return $this->hasMany(TransferRecipient::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** Same alphabet and length as a file request's — see that model. */
    public static function generateSlug(): string
    {
        do {
            $slug = Str::lower(Str::random(10));
        } while (static::query()->where('slug', $slug)->exists());

        return $slug;
    }
}
