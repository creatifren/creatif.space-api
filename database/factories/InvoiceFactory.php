<?php

namespace Database\Factories;

use App\Models\Invoice;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'subscription_id' => Subscription::factory(),
            'number' => 'INV-'.now()->year.'-'.fake()->unique()->numerify('######'),
            'amount' => 94_000,
            'status' => 'pending',
            'midtrans_order_id' => 'CS-'.fake()->unique()->numerify('##########'),
            'midtrans_snap_token' => null,
            'payment_method' => null,
            'due_at' => now()->addDay(),
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
}
