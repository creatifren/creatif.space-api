<?php

namespace Database\Factories;

use App\Models\Approval;
use App\Models\Client;
use App\Models\SpaceItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Approval>
 */
class ApprovalFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'space_item_id' => SpaceItem::factory(),
            'client_id' => Client::factory(),
            'status' => 'pending',
        ];
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'approved',
            'approved_at' => now()->subHour(),
        ]);
    }

    public function revision(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'revision',
        ]);
    }

    /**
     * An approval the owner reset.
     */
    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'cancelled',
            'cancelled_reason' => 'owner_reset',
        ]);
    }
}
