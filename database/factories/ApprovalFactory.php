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
            'version_hash_at_approval' => 'hash-at-approval',
        ]);
    }

    public function revision(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'revision',
        ]);
    }

    /**
     * An approval the file itself invalidated.
     */
    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'cancelled',
            'cancelled_reason' => 'file_version_changed',
        ]);
    }
}
