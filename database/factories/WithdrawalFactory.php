<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Withdrawal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Withdrawal>
 */
class WithdrawalFactory extends Factory
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
            'amount' => 100_000,
            'bank_code' => 'bca',
            'account_number' => '1234564471',
            'account_name' => fake()->name(),
            'status' => 'pending',
            'midtrans_payout_id' => null,
            'processed_at' => null,
            'failure_reason' => null,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
            'processed_at' => now()->subDay(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'failed',
            'processed_at' => now()->subDay(),
            'failure_reason' => 'Account number rejected by the bank',
        ]);
    }
}
