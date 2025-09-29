<?php

namespace Database\Factories;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Plan>
 */
class PlanFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->word . ' Plan',
            'description' => $this->faker->sentence(),
            'price' => $this->faker->randomFloat(2, 5, 100),
            'regular_price' => $this->faker->randomFloat(2, 10, 150),
            'discount_percentage' => $this->faker->randomFloat(2, 0, 50),
            'has_discount' => $this->faker->boolean(),
            'currency' => 'USD',
            'interval' => $this->faker->randomElement(['monthly', 'yearly']),
            'tokens_per_cycle' => $this->faker->numberBetween(1000, 10000),
            'features' => json_encode(['feature1', 'feature2', 'feature3']),
            'stripe_price_id' => 'price_' . $this->faker->md5(),
            'is_active' => true,
            'premium_models_access' => $this->faker->boolean()
        ];
    }
}
