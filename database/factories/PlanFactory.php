<?php

namespace Database\Factories;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => 'pro',
            'name' => 'Pro',
            'price_monthly' => 999,
            'price_yearly' => 9990,
            'quotas' => [
                'storage_bytes' => 25 * 1024 * 1024 * 1024,
                'spaces_total' => null,
                'spaces_active' => null,
                'seats' => 1,
                'custom_domains' => 1,
                'credits_monthly' => 45000,
                'social_posts_monthly' => 150,
            ],
            'features' => ['branding_removed' => true],
            'is_active' => true,
            'sort_order' => 2,
        ];
    }

    /**
     * The plan everyone has without paying — the one with real ceilings.
     */
    public function free(): static
    {
        return $this->state(fn (array $attributes) => [
            'key' => 'free',
            'name' => 'Free',
            'price_monthly' => 0,
            'price_yearly' => 0,
            'quotas' => [
                'storage_bytes' => 1 * 1024 * 1024 * 1024,
                'spaces_total' => 15,
                'spaces_active' => null,
                'seats' => 1,
                'custom_domains' => 0,
                'credits_monthly' => 3000,
                'social_posts_monthly' => 10,
                'social_accounts' => 3,
            ],
            'features' => ['branding_removed' => false],
            'sort_order' => 1,
        ]);
    }

    public function team(): static
    {
        return $this->state(fn (array $attributes) => [
            'key' => 'team',
            'name' => 'Team / Agency',
            'price_monthly' => 270_000,
            'price_yearly' => 2_430_000,
            'quotas' => [
                'storage_bytes' => 25 * 1024 * 1024 * 1024,
                'spaces_total' => null,
                'spaces_active' => null,
                'seats' => 3,
                'custom_domains' => 1,
                'credits_monthly' => 45000,
                'social_posts_monthly' => 150,
            ],
            'sort_order' => 3,
        ]);
    }
}
