<?php

namespace Database\Factories;

use App\Models\FileRequest;
use App\Models\FileRequestSubmission;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FileRequestSubmission>
 */
class FileRequestSubmissionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'file_request_id' => FileRequest::factory(),
            'sender_name' => fake()->name(),
            'sender_email' => fake()->safeEmail(),
            'message' => null,
            'files' => [],
            'status' => 'uploading',
        ];
    }

    public function stored(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'stored',
            'files' => [['name' => 'brief.pdf', 'provider_file_id' => 'drive-file-1']],
        ]);
    }
}
