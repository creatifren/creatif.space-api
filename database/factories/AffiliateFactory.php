<?php

namespace Database\Factories;

use App\Models\Affiliate;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Affiliate>
 */
class AffiliateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'status' => 'applied',
            'code' => Str::upper(Str::random(8)),
            'tier_percent' => '20.00',
            'paid_referrals_count' => 0,
            'application' => [
                'link' => 'https://instagram.com/'.fake()->userName(),
                'audience' => 'Photographers in Bandung.',
            ],
        ];
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'approved',
            'approved_at' => now()->subWeek(),
        ]);
    }

    /** An affiliate who has already earned their way up a tier. */
    public function atTier(int $percent, int $paidReferrals): static
    {
        return $this->state(fn (array $attributes) => [
            'tier_percent' => number_format($percent, 2, '.', ''),
            'paid_referrals_count' => $paidReferrals,
        ]);
    }
}
