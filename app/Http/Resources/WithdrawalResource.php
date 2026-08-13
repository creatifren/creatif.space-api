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
            'amount' => $this->amount,
            'bank_code' => $this->bank_code,
            'account_masked' => $this->maskedAccount(),
            'account_name' => $this->account_name,
            'status' => $this->status,
            'processed_at' => $this->processed_at,
            'failure_reason' => $this->failure_reason,
            'created_at' => $this->created_at,
        ];
    }
}
