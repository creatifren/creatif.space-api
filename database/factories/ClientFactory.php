<?php

namespace Database\Factories;

use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Client>
 */
class ClientFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'google_id' => (string) fake()->unique()->numerify('####################'),
            'email' => fake()->unique()->safeEmail(),
            'name' => fake()->name(),
            'avatar_url' => null,
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * A client who never signed in with Google — email only.
     */
    public function withoutGoogle(): static
    {
        return $this->state(fn (array $attributes) => [
            'google_id' => null,
        ]);
    }
}
