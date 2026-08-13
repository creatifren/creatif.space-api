<?php

namespace Database\Factories;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
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
            // The seeded rows are the plans — `key` is unique, so making a
            // fresh one would collide. Tests name the plan they want.
            'plan_id' => fn () => Plan::query()->where('key', 'premium')->value('id')
                ?? Plan::factory(),
            'billing_period' => 'monthly',
            'status' => 'active',
            'seats' => 1,
            'current_period_start' => now()->subDays(5),
            'current_period_end' => now()->addDays(25),
            'grace_ends_at' => null,
            'cancelled_at' => null,
        ];
    }

    /**
     * Payment missed: still entitled, but on the clock.
     */
    public function pastDue(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'past_due',
            'current_period_end' => now()->subDay(),
            'grace_ends_at' => now()->addDays(6),
        ]);
    }

    /**
     * The period has run out and nobody paid — back to Free.
     */
    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'expired',
            'current_period_end' => now()->subDays(8),
            'grace_ends_at' => now()->subDay(),
        ]);
    }

    /**
     * Cancelled, but paid up until the period ends.
     */
    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'cancelled_at' => now()->subDay(),
        ]);
    }
}
