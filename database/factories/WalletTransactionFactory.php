<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WalletTransaction>
 *
 * Prefer App\Support\Wallet in tests that care about the running balance —
 * this factory writes a line without recomputing it.
 */
class WalletTransactionFactory extends Factory
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
            'type' => 'sale_credit',
            'amount' => 141_550,
            'balance_after' => 141_550,
            'reference_type' => null,
            'reference_id' => null,
            'note' => null,
        ];
    }
}
