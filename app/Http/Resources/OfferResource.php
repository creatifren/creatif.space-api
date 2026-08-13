<?php

namespace App\Http\Resources;

use App\Models\Offer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Offer
 */
class OfferResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'space_id' => $this->space?->ulid,
            'type' => $this->type,
            'title' => $this->title,
            'description' => $this->description,
            'price' => $this->price,
            'currency' => $this->currency,
            'price_from' => $this->price_from,
            'show_on_space' => $this->show_on_space,
            'show_on_profile' => $this->show_on_profile,
            'details' => $this->details,
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,
            // Derived, so the buy button and the server can't disagree about
            // what is actually purchasable.
            'buyable' => $this->isBuyable(),
            'needs_amount' => $this->needsBuyerAmount(),
        ];
    }
}
