<?php

namespace Database\Factories;

use App\Models\DriveAccount;
use App\Models\DriveFile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DriveFile>
 */
class DriveFileFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'drive_account_id' => DriveAccount::factory(),
            'provider_file_id' => fake()->unique()->regexify('[A-Za-z0-9_-]{28}'),
            'name' => fake()->words(3, true).'.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => fake()->numberBetween(50_000, 12_000_000),
            'thumbnail_url' => 'https://lh3.googleusercontent.com/thumb/'.fake()->uuid(),
            'version_hash' => fake()->md5(),
            'is_folder' => false,
            'last_synced_at' => now(),
        ];
    }

    /**
     * A folder rather than a file.
     */
    public function folder(): static
    {
        return $this->state(fn (array $attributes) => [
            'mime_type' => 'application/vnd.google-apps.folder',
            'is_folder' => true,
            'size_bytes' => null,
            'thumbnail_url' => null,
            'version_hash' => null,
        ]);
    }
}
