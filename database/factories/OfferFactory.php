<?php

namespace Database\Factories;

use App\Models\Offer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Offer>
 */
class OfferFactory extends Factory
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
            'space_id' => null,
            'type' => 'product',
            'title' => fake()->words(3, true),
            'description' => null,
            'price' => 149_000,
            'currency' => 'IDR',
            'price_from' => false,
            'show_on_space' => true,
            'show_on_profile' => true,
            'details' => null,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    /**
     * A service: quoted "starting at", settled in conversation.
     */
    public function service(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'service',
            'price' => 3_500_000,
            'price_from' => true,
        ]);
    }

    /**
     * A tip — the buyer names the amount.
     */
    public function tip(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'tip',
            'title' => 'Buy me a coffee',
            'price' => null,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
