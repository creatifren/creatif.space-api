<?php

namespace App\Support;

use App\Enums\PlanKey;
use App\Models\Plan;
use Illuminate\Validation\ValidationException;

/** Which of our plans a Creem product id is. */
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
                        return [Plan::query()->where('key', $key)->where('is_active', true)->firstOrFail(), $period];
                    }
                }
            }
            // The no-trial twins are Pro all the same.
            foreach (Trial::noTrialProducts() as $period => $id) {
                if ($product === $id) {
                    return [Plan::query()->where('key', PlanKey::Pro)->where('is_active', true)->firstOrFail(), $period];
                }
            }
        }

        throw ValidationException::withMessages(['object' => 'Unknown product.']);
    }

    /**
     * The products a checkout for this plan and period may come back with.
     *
     * @return list<string>
     */
    public static function accepted(Plan $plan, string $period): array
    {
        return array_values(array_filter([
            $plan->creemProduct($period),
            $plan->key === PlanKey::Pro ? Trial::noTrialProduct($period) : null,
        ]));
    }

    public static function id(mixed $value): ?string
    {
        return is_string($value) ? $value : (is_array($value) && is_string($value['id'] ?? null) ? $value['id'] : null);
    }
}
