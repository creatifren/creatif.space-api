<?php

namespace Database\Factories;

use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SocialAccount>
 */
class SocialAccountFactory extends Factory
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
            'platform' => 'instagram',
            'provider_account_id' => 'acc_'.fake()->unique()->lexify('????????????'),
            'username' => '@'.fake()->userName(),
            'profile_photo_url' => null,
            'status' => 'connected',
            'connected_at' => now(),
        ];
    }

    /**
     * An account the provider no longer holds a grant for.
     */
    public function disconnected(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'disconnected',
        ]);
    }
}
