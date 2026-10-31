<?php

namespace App\Http\Resources;

use App\Models\Withdrawal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Withdrawal
 */
class WithdrawalResource extends JsonResource
{
    /**
     * The account number is masked: a payout history screen has no reason
     * to print it in full.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'wallet' => $this->wallet,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'paypal_email' => $this->paypal_email,
            'payout_reference' => $this->payout_reference,
            'status' => $this->status,
            'processed_at' => $this->processed_at,
            'failure_reason' => $this->failure_reason,
            'created_at' => $this->created_at,
        ];
    }
}
