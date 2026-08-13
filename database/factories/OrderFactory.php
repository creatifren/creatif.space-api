<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $amount = 149_000;
        $split = Order::split($amount, '5.00');

        return [
            'creator_id' => User::factory(),
            'client_id' => Client::factory(),
            'offer_id' => null,
            'space_id' => null,
            'amount' => $amount,
            'fee_percent' => '5.00',
            'fee_amount' => $split['fee'],
            'net_amount' => $split['net'],
            'status' => 'pending',
            'midtrans_order_id' => 'OR-'.fake()->unique()->numerify('##########'),
            'payment_method' => null,
            'paid_at' => null,
            'raw_notification' => null,
        ];
    }

    public function paid(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'paid',
            'payment_method' => 'qris',
            'paid_at' => now()->subHour(),
        ]);
    }

    /**
     * Sold while the creator was on a paid plan — half the fee.
     */
    public function atPaidFee(): static
    {
        return $this->state(function (array $attributes) {
            $split = Order::split((int) $attributes['amount'], '2.50');

            return [
                'fee_percent' => '2.50',
                'fee_amount' => $split['fee'],
                'net_amount' => $split['net'],
            ];
        });
    }
}
