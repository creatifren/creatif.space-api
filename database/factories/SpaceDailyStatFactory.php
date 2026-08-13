<?php

namespace Database\Factories;

use App\Models\Space;
use App\Models\SpaceDailyStat;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SpaceDailyStat>
 */
class SpaceDailyStatFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $views = fake()->numberBetween(0, 200);

        return [
            'space_id' => Space::factory(),
            'date' => now()->toDateString(),
            'views' => $views,
            // Fewer people than views: the same browser comes back.
            'unique_visitors' => (int) ceil($views / 3),
            'ai_crawler_hits' => fake()->numberBetween(0, 20),
            'top_referrers' => null,
        ];
    }
}
