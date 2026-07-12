<?php

namespace Database\Factories;

use App\Enums\PlanStatus;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Plan>
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
            'team_id' => Team::factory(),
            'product_id' => Product::factory(),
            'code' => null,
            'name' => fake()->randomElement(['Basic', 'Standard', 'Premium', 'Pro']).' '.fake()->unique()->numberBetween(1, 9999),
            'status' => PlanStatus::Active,
            'custom_data' => null,
        ];
    }

    /**
     * Indicate that the plan is a draft — not yet purchasable.
     */
    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PlanStatus::Draft,
        ]);
    }

    /**
     * Indicate that the plan is archived.
     */
    public function archived(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PlanStatus::Archived,
        ]);
    }
}
