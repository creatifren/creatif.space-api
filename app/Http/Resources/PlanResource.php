<?php

namespace App\Http\Resources;

use App\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Plan
 */
class PlanResource extends JsonResource
{
    /**
     * The pricing table, as the public page reads it. `key` is the id here:
     * the internal one never ships, and "premium" is what checkout sends.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'key' => $this->key,
            'name' => $this->name,
            'price_monthly' => $this->price_monthly,
            'price_yearly' => $this->price_yearly,
            'seat_price_monthly' => $this->seat_price_monthly,
            // null inside quotas means unlimited, and travels as null.
            'quotas' => $this->quotas,
            'features' => $this->features,
            'sort_order' => $this->sort_order,
        ];
    }
}
