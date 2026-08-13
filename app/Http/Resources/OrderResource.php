<?php

namespace App\Http\Resources;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Order
 */
class OrderResource extends JsonResource
{
    /**
     * One purchase, from either side of the till. The fee is shown as it
     * was charged, not as it would be today.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'title' => $this->offerTitle(),
            'type' => $this->offer?->type,
            'amount' => $this->amount,
            'fee_percent' => (float) $this->fee_percent,
            'fee_amount' => $this->fee_amount,
            'net_amount' => $this->net_amount,
            'status' => $this->status,
            'payment_method' => $this->payment_method,
            'paid_at' => $this->paid_at,
            'created_at' => $this->created_at,
            'seller' => $this->whenLoaded('creator', fn () => [
                'name' => $this->creator->name,
                'handle' => $this->creator->handle?->name,
            ]),
            'buyer' => $this->whenLoaded('client', fn () => [
                'name' => $this->client->displayName(),
                'email' => $this->client->email,
            ]),
            'space' => $this->whenLoaded('space', fn () => $this->space === null ? null : [
                'title' => $this->space->title,
                'slug' => $this->space->slug,
            ]),
        ];
    }
}
