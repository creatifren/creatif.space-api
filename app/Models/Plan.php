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
 * @property int $price_monthly US cents
 * @property int $price_yearly US cents
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
            'quotas' => 'array',
            'features' => 'array',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * `key` is the public identity — "pro" is what the checkout call sends,
     * and it never changes.
     */
    public function getRouteKeyName(): string
    {
        return 'key';
    }

    /**
     * The backing string, not the enum case. `getRouteKey()` defaults to
     * `getAttribute('key')`, which the cast hands back as a PlanKey object —
     * and every URL built from it (Filament's row Edit links, route('...',
     * $plan), redirects) dies stringifying it. Casting the column and routing
     * on it is the combination that needs this; without it /admin/plans
     * cannot render a single row.
     */
    public function getRouteKey(): string
    {
        return $this->key->value;
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
     * What one billing period costs, in US cents. Pro and Max are one unit;
     * Team, when it ships, multiplies this by the seats.
     */
    public function priceFor(string $period): int
    {
        return $period === 'yearly' ? $this->price_yearly : $this->price_monthly;
    }

    /**
     * The Creem product that sells this plan for the period. Products differ
     * between test and live mode, so they come from config, not this row.
     */
    public function creemProduct(string $period): ?string
    {
        return config("services.creem.products.{$this->key->value}_{$period}");
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
