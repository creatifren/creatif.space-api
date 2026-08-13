<?php

namespace Database\Factories;

use App\Models\DriveAccount;
use App\Models\FileRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FileRequest>
 */
class FileRequestFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $user = User::factory();

        return [
            'user_id' => $user,
            'title' => fake()->words(3, true),
            'note' => null,
            'drive_account_id' => DriveAccount::factory()->for($user),
            'target_folder_id' => 'folder-'.fake()->unique()->numerify('########'),
            'max_files' => 5,
            'max_mb' => 100,
            'status' => 'open',
            'expires_at' => now()->addDays(FileRequest::DEFAULT_DAYS),
        ];
    }

    public function closed(): static
    {
        return $this->state(fn (array $attributes) => ['status' => 'closed']);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'expires_at' => now()->subDay(),
        ]);
    }
}
