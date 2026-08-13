<?php

namespace Database\Factories;

use App\Models\Affiliate;
use App\Models\Commission;
use App\Models\Invoice;
use App\Models\Referral;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Commission>
 */
class CommissionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'referral_id' => Referral::factory(),
            'invoice_id' => Invoice::factory(),
            'amount' => 1_566,
            'tier_percent' => '20.00',
            'installment_no' => 1,
            'status' => 'holding',
            'hold_until' => now()->addDays(Affiliate::HOLD_DAYS),
        ];
    }

    /** Held long enough that the release sweep will take it. */
    public function due(): static
    {
        return $this->state(fn (array $attributes) => [
            'hold_until' => now()->subDay(),
        ]);
    }
}
