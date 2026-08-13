<?php

namespace Database\Factories;

use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationPreference>
 */
class NotificationPreferenceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'type' => 'approval.decided',
            'email_enabled' => true,
            'bell_enabled' => true,
        ];
    }

    public function silenced(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_enabled' => false,
            'bell_enabled' => false,
        ]);
    }
}
