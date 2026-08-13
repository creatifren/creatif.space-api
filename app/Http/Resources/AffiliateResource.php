<?php

namespace App\Http\Resources;

use App\Models\Affiliate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Affiliate
 */
class AffiliateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'status' => $this->status,
            'code' => $this->code,
            'link' => config('app.frontend_url').'/?ref='.$this->code,
            'tier_percent' => (float) $this->tier_percent,
            'next_tier' => $this->nextTier(),
            'paid_referrals' => $this->paid_referrals_count,
            'approved_at' => $this->approved_at,
        ];
    }
}
