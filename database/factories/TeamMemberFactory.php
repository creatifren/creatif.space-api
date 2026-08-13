<?php

namespace Database\Factories;

use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TeamMember>
 */
class TeamMemberFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'owner_id' => User::factory(),
            'member_id' => null,
            'email' => fake()->unique()->safeEmail(),
            'role' => 'editor',
            'status' => 'invited',
            'invited_at' => now(),
        ];
    }

    /** Somebody who has signed in and taken the seat. */
    public function active(User $member): static
    {
        return $this->state(fn (array $attributes) => [
            'member_id' => $member->id,
            'email' => $member->email,
            'status' => 'active',
            'joined_at' => now(),
        ]);
    }

    public function viewer(): static
    {
        return $this->state(fn (array $attributes) => ['role' => 'viewer']);
    }
}
