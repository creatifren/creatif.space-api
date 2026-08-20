<?php

namespace Database\Factories;

use App\Models\File;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<File>
 */
class FileFactory extends Factory
{
    /**
     * Define the model's default state: a ready image on the s3 disk.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'disk' => 's3',
            'path' => 'u/test/'.fake()->unique()->regexify('[0-9A-HJKMNP-TV-Z]{26}').'.jpg',
            'name' => fake()->words(3, true).'.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => fake()->numberBetween(50_000, 12_000_000),
            'checksum' => fake()->md5(),
            'status' => File::STATUS_READY,
            'width' => 4000,
            'height' => 3000,
            'source' => 'upload',
        ];
    }

    /**
     * An upload that was presigned but never completed.
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => File::STATUS_PENDING,
            'checksum' => null,
            'width' => null,
            'height' => null,
        ]);
    }

    /**
     * A file copied in from Google Drive.
     */
    public function driveImport(): static
    {
        return $this->state(fn (array $attributes) => [
            'source' => 'drive_import',
            'source_meta' => [
                'provider_file_id' => fake()->regexify('[A-Za-z0-9_-]{28}'),
                'drive_email' => fake()->safeEmail(),
            ],
        ]);
    }
}
