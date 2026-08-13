<?php

namespace Database\Factories;

use App\Models\Approval;
use App\Models\ApprovalNote;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApprovalNote>
 */
class ApprovalNoteFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'approval_id' => Approval::factory(),
            'author_type' => 'client',
            'body' => fake()->sentence(),
        ];
    }

    public function fromOwner(): static
    {
        return $this->state(fn (array $attributes) => [
            'author_type' => 'owner',
            'chips' => null,
        ]);
    }
}
