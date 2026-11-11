<?php

namespace App\Http\Resources;

use App\Enums\PlanKey;
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
        // Enterprise: listed, never sold — contact sales, no checkout, and
        // no price (agreed per contract).
        $contactSales = $this->feature('contact_sales');

        return [
            'key' => $this->key,
            'name' => $this->name,
            'price_monthly' => $contactSales ? null : $this->price_monthly,
            'price_yearly' => $contactSales ? null : $this->price_yearly,
            'contact_sales' => $contactSales,
            'checkout' => in_array($this->key->value, PlanKey::sold(), true),
            'currency' => 'USD',
            // null inside quotas means unlimited, and travels as null.
            'quotas' => $this->quotas,
            'features' => $this->features,
            'sort_order' => $this->sort_order,
        ];
    }
}
