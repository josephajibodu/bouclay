<?php

namespace Database\Factories;

use App\Enums\BillingInterval;
use App\Models\Price;
use App\Models\PricePhase;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PricePhase>
 */
class PricePhaseFactory extends Factory
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
            'sequence' => 0,
            'charge_price_id' => Price::factory()->phaseOnly(),
            'duration_interval' => BillingInterval::Month,
            'duration_count' => 1,
        ];
    }
}
