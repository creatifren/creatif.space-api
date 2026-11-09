<?php

namespace App\Http\Resources;

use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Subscription
 */
class SubscriptionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'plan' => [
                'key' => $this->plan->key,
                'name' => $this->plan->name,
            ],
            'billing_period' => $this->billing_period,
            // active | trialing | past_due | cancelled | expired
            'status' => $this->status,
            // Creem's current_period_end while trialing: the first charge,
            // or — cancelled — the day it goes back to Free.
            'trial_ends_at' => $this->isTrialing() ? $this->current_period_end : null,
            'seats' => $this->seats,
            'current_period_start' => $this->current_period_start,
            'current_period_end' => $this->current_period_end,
            'grace_ends_at' => $this->grace_ends_at,
            'cancelled_at' => $this->cancelled_at,
            // Derived so the screen doesn't have to re-implement the rule.
            'ends_at_period_end' => $this->isEndingAtPeriodEnd(),
            'in_grace' => $this->isInGrace(),
        ];
    }
}
