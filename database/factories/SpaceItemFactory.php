<?php

namespace Database\Factories;

use App\Models\DriveFile;
use App\Models\Space;
use App\Models\SpaceItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SpaceItem>
 */
class SpaceItemFactory extends Factory
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
            'drive_file_id' => DriveFile::factory(),
            'sort_order' => fake()->numberBetween(0, 50),
        ];
    }
}
