<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'google_id' => (string) fake()->unique()->numberBetween(100_000_000_000, 999_999_999_999),
            'email_verified_at' => now(),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the user has completed onboarding.
     */
    public function onboarded(): static
    {
        return $this->state(fn (array $attributes) => [
            'onboarded_at' => now(),
        ]);
    }
}
