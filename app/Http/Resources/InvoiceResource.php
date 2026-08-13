<?php

namespace App\Http\Resources;

use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Invoice
 */
class InvoiceResource extends JsonResource
{
    /**
     * A line in the billing history. The Snap token never ships here — it
     * belongs to the checkout response that created it.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'number' => $this->number,
            'amount' => $this->amount,
            'status' => $this->status,
            'payment_method' => $this->payment_method,
            'due_at' => $this->due_at,
            'paid_at' => $this->paid_at,
            'created_at' => $this->created_at,
        ];
    }
}
