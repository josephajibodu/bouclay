<?php

namespace Database\Factories;

use App\Enums\CatalogStatus;
use App\Models\PricingJourney;
use App\Models\Product;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PricingJourney>
 */
class PricingJourneyFactory extends Factory
{
    protected $model = PricingJourney::class;

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
            'name' => fake()->words(2, true).' Offer',
            'description' => null,
            'status' => CatalogStatus::Active,
        ];
    }

    /**
     * Indicate the journey is archived.
     */
    public function archived(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => CatalogStatus::Archived,
        ]);
    }
}
