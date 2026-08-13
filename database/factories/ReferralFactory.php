<?php

namespace Database\Factories;

use App\Models\Affiliate;
use App\Models\Referral;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Referral>
 */
class ReferralFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'affiliate_id' => Affiliate::factory()->approved(),
            'referred_user_id' => User::factory(),
            'clicked_at' => now()->subDays(3),
            'signed_up_at' => now()->subDays(2),
            'cookie_expires_at' => now()->addDays(Affiliate::COOKIE_DAYS),
        ];
    }

    /** A click that never became an account — the funnel's first step. */
    public function clickOnly(): static
    {
        return $this->state(fn (array $attributes) => [
            'referred_user_id' => null,
            'signed_up_at' => null,
        ]);
    }

    public function paying(): static
    {
        return $this->state(fn (array $attributes) => [
            'first_paid_at' => now()->subMonth(),
        ]);
    }
}
