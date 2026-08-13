<?php

namespace Database\Factories;

use App\Enums\SpaceEventType;
use App\Models\Space;
use App\Models\SpaceEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SpaceEvent>
 */
class SpaceEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'space_id' => Space::factory(),
            'type' => SpaceEventType::View,
            'visitor_hash' => sha1(fake()->unique()->uuid()),
            'referrer_host' => null,
            'is_ai_crawler' => false,
            'crawler_name' => null,
            'created_at' => now(),
        ];
    }

    public function crawler(string $name = 'GPTBot'): static
    {
        return $this->state(fn (array $attributes) => [
            'is_ai_crawler' => true,
            'crawler_name' => $name,
        ]);
    }

    public function from(string $host): static
    {
        return $this->state(fn (array $attributes) => [
            'referrer_host' => $host,
        ]);
    }

    public function on(\DateTimeInterface $at): static
    {
        return $this->state(fn (array $attributes) => [
            'created_at' => $at,
        ]);
    }
}
