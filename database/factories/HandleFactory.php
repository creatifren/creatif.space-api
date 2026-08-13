<?php

namespace Database\Factories;

use App\Models\Handle;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Handle>
 */
class HandleFactory extends Factory
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
            'name' => fake()->unique()->regexify('[a-z]{5,12}'),
            'is_reserved' => false,
        ];
    }

    /**
     * A system-reserved handle nobody may claim.
     */
    public function reserved(): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => null,
            'is_reserved' => true,
        ]);
    }

    /**
     * A handle released by its previous owner.
     */
    public function released(?\DateTimeInterface $at = null): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => null,
            'released_at' => $at ?? now()->subDays(60),
        ]);
    }
}
