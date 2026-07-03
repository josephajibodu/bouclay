<?php

namespace Database\Factories;

use App\Enums\CatalogStatus;
use App\Models\Product;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
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
            'name' => fake()->words(2, true),
            'description' => fake()->sentence(),
            'category' => null,
            'image_url' => null,
            'status' => CatalogStatus::Active,
            'custom_data' => null,
        ];
    }

    /**
     * Indicate that the product is archived.
     */
    public function archived(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => CatalogStatus::Archived,
        ]);
    }
}
