<?php

namespace Database\Factories;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Subscription>
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
            'plan_id' => Plan::factory(),
            'stripe_subscription_id' => 'sub_' . $this->faker->md5(),
            // 'stripe_customer_id' removed to match database schema
            'status' => $this->faker->randomElement(['active', 'canceled', 'past_due', 'incomplete']),
            'trial_ends_at' => $this->faker->boolean(30) ? $this->faker->dateTimeBetween('+1 day', '+30 days') : null,
            'ends_at' => $this->faker->boolean(30) ? $this->faker->dateTimeBetween('+1 month', '+12 months') : null,
            'canceled_at' => $this->faker->boolean(20) ? $this->faker->dateTimeBetween('-30 days', 'now') : null,
        ];
    }
    
    /**
     * Configure the model factory.
     *
     * @return $this
     */
    public function active()
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => 'active',
                'canceled_at' => null,
                'ends_at' => null,
            ];
        });
    }
    
    /**
     * Configure the model factory to create a canceled subscription.
     *
     * @return $this
     */
    public function canceled()
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => 'canceled',
                'canceled_at' => now(),
                'ends_at' => $this->faker->dateTimeBetween('+1 day', '+30 days'),
            ];
        });
    }
}
