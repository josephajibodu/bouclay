<?php

namespace Database\Factories;

use App\Models\Price;
use App\Models\PriceTier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PriceTier>
 */
class PriceTierFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'price_id' => Price::factory(),
            'tier_index' => 0,
            'up_to' => null,
            'unit_amount' => 100000,
            'flat_amount' => null,
        ];
    }
}
