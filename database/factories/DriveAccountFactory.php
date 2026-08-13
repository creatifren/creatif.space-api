<?php

namespace Database\Factories;

use App\Models\DriveAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DriveAccount>
 */
class DriveAccountFactory extends Factory
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
            'provider' => 'google',
            'provider_account_id' => (string) fake()->unique()->numberBetween(100_000_000_000, 999_999_999_999),
            'email' => fake()->unique()->safeEmail(),
            'access_token' => 'test-access-token',
            'refresh_token' => 'test-refresh-token',
            'token_expires_at' => now()->addHour(),
            'status' => 'connected',
        ];
    }

    /**
     * An account whose token was revoked upstream.
     */
    public function reconnectNeeded(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'reconnect_needed',
        ]);
    }
}
