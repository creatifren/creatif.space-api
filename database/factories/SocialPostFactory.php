<?php

namespace Database\Factories;

use App\Models\SocialPost;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SocialPost>
 */
class SocialPostFactory extends Factory
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
            'provider_post_id' => 'post_'.fake()->unique()->lexify('????????????'),
            'caption' => fake()->sentence(),
            'media' => [['url' => 'https://example.com/photo.jpg']],
            'scheduled_at' => now()->addDay(),
            'status' => 'scheduled',
        ];
    }

    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'published',
            'scheduled_at' => null,
        ]);
    }
}
