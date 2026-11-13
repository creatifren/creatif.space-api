<?php

namespace App\Support;

use App\Models\Plan;
use Illuminate\Validation\ValidationException;

/**
 * Which of our plans a Creem product id is. A checkout only ever uses the
 * plan's current product (charged at once — no plan has a trial); the
 * legacy ids are product ids existing subscriptions still renew on.
 */
class CreemProducts
{
    /**
     * @return array{0: Plan, 1: string} the plan and its billing period
     *
     * @throws ValidationException for a product we do not sell
     */
    public static function plan(?string $product): array
    {
        if ($product !== null && $product !== '') {
            foreach (['pro', 'max'] as $key) {
                foreach (['monthly', 'yearly'] as $period) {
                    if ($product === config("services.creem.products.{$key}_{$period}")) {
                        return [self::active($key), $period];
                    }
                }
            }
            $legacy = self::legacy()[$product] ?? null;
            if ($legacy !== null) {
                [$key, $period] = explode('_', $legacy, 2);

                return [self::active($key), $period];
            }
        }

        throw ValidationException::withMessages(['object' => 'Unknown product.']);
    }

    /**
     * The products a checkout for this plan and period may come back with:
     * the current one, or an earlier one for the same plan (a checkout
     * opened before the product changed).
     *
     * @return list<string>
     */
    public static function accepted(Plan $plan, string $period): array
    {
        $key = $plan->key->value.'_'.$period;

        return array_values(array_unique(array_filter([
            $plan->creemProduct($period),
            ...array_keys(array_filter(self::legacy(), fn (string $k) => $k === $key)),
        ])));
    }

    /**
     * Earlier product ids by plan: id => "pro_monthly". From
     * CREEM_LEGACY_PRODUCTS ("prod_a=pro_monthly,…") and, deprecated, the
     * old no-trial Pro twins.
     *
     * @return array<string, string>
     */
    public static function legacy(): array
    {
        $map = [];
        foreach (explode(',', (string) config('services.creem.legacy_products', '')) as $pair) {
            [$id, $key] = array_map('trim', array_pad(explode('=', $pair, 2), 2, ''));
            if ($id !== '' && preg_match('/^(pro|max)_(monthly|yearly)$/', $key) === 1) {
                $map[$id] = $key;
            }
        }
        foreach ((array) config('services.creem.legacy_notrial', []) as $key => $id) {
            if (is_string($id) && $id !== '' && ! isset($map[$id])) {
                $map[$id] = (string) $key;
            }
        }

        return $map;
    }

    public static function id(mixed $value): ?string
    {
        return is_string($value) ? $value : (is_array($value) && is_string($value['id'] ?? null) ? $value['id'] : null);
    }

    private static function active(string $key): Plan
    {
        return Plan::query()->where('key', $key)->where('is_active', true)->firstOrFail();
    }
}
