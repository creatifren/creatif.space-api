<?php

namespace App\Models;

use App\Enums\OfferType;
use Carbon\CarbonImmutable;
use Database\Factories\OfferFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Something for sale.
 *
 * @property int $id
 * @property string $ulid
 * @property int $user_id
 * @property int|null $space_id
 * @property OfferType $type
 * @property string $title
 * @property string|null $description
 * @property int|null $price
 * @property string $currency
 * @property bool $price_from
 * @property bool $show_on_space
 * @property bool $show_on_profile
 * @property array<string, mixed>|null $details
 * @property bool $is_active
 * @property int $sort_order
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class Offer extends Model
{
    /** @use HasFactory<OfferFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'space_id',
        'type',
        'title',
        'description',
        'price',
        'price_from',
        'show_on_space',
        'show_on_profile',
        'details',
        'is_active',
        'sort_order',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => OfferType::class,
            'price' => 'integer',
            'price_from' => 'boolean',
            'show_on_space' => 'boolean',
            'show_on_profile' => 'boolean',
            'details' => 'array',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Assign a public ULID on creation.
     */
    protected static function booted(): void
    {
        static::creating(function (self $offer): void {
            $offer->ulid ??= (string) str()->ulid();
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
     * @return BelongsTo<Space, $this>
     */
    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class);
    }

    /**
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * A tip has no set price — the buyer names the amount, so checkout has
     * to be told one.
     */
    public function needsBuyerAmount(): bool
    {
        return $this->type === OfferType::Tip || $this->price === null;
    }

    /**
     * Services end in a conversation, not a checkout: "starting at" is an
     * opening line, and there is nothing fixed to charge.
     */
    public function isBuyable(): bool
    {
        return $this->is_active
            && ! $this->price_from
            && $this->type !== OfferType::Service;
    }
}
