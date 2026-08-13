<?php

namespace App\Models;

use App\Enums\PlanKey;
use Carbon\CarbonImmutable;
use Database\Factories\PlanFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Pricing, as data. Nothing in the code may hard-code a plan number —
 * /pricing renders these rows and quota enforcement reads them.
 *
 * @property int $id
 * @property PlanKey $key
 * @property string $name
 * @property int $price_monthly
 * @property int $price_yearly
 * @property string $fee_percent
 * @property int $seat_price_monthly
 * @property array<string, mixed> $quotas
 * @property array<string, mixed> $features
 * @property bool $is_active
 * @property int $sort_order
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class Plan extends Model
{
    /** @use HasFactory<PlanFactory> */
    use HasFactory;

    protected $fillable = [
        'key',
        'name',
        'price_monthly',
        'price_yearly',
        'fee_percent',
        'seat_price_monthly',
        'quotas',
        'features',
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
            'key' => PlanKey::class,
            'price_monthly' => 'integer',
            'price_yearly' => 'integer',
            'seat_price_monthly' => 'integer',
            'quotas' => 'array',
            'features' => 'array',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * `key` is the public identity — "premium" is what the checkout call
     * sends, and it never changes.
     */
    public function getRouteKeyName(): string
    {
        return 'key';
    }

    /**
     * @return HasMany<Subscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * A ceiling from `quotas`, or null for "no limit" — which is a real
     * answer here, not a missing one.
     */
    public function quota(string $name): ?int
    {
        $value = $this->quotas[$name] ?? null;

        return $value === null ? null : (int) $value;
    }

    public function feature(string $name): bool
    {
        return (bool) ($this->features[$name] ?? false);
    }

    /**
     * The price actually charged for a period, seats included. Team bills
     * per person beyond the seats the plan already covers.
     */
    public function priceFor(string $period, int $seats = 1): int
    {
        $included = $this->quota('seats') ?? $seats;
        $extra = max(0, $seats - $included);

        return $period === 'yearly'
            // A yearly seat costs ten months, the same three-months-free
            // arithmetic the plan price itself uses.
            ? $this->price_yearly + $extra * $this->seat_price_monthly * 10
            : $this->price_monthly + $extra * $this->seat_price_monthly;
    }

    /**
     * The plan everyone has without paying. Resolved from the table, never
     * invented — a missing `free` row is a broken install, not a default.
     */
    public static function free(): self
    {
        return static::query()->where('key', PlanKey::Free)->firstOrFail();
    }
}
