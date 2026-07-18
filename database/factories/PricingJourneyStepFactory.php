<?php

namespace Database\Factories;

use App\Enums\BillingInterval;
use App\Models\Price;
use App\Models\PricingJourney;
use App\Models\PricingJourneyStep;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PricingJourneyStep>
 */
class PricingJourneyStepFactory extends Factory
{
    protected $model = PricingJourneyStep::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'price_phases_id' => PricingJourney::factory(),
            'sequence' => 0,
            'price_id' => Price::factory()->phaseOnly(),
            'quantity' => 1,
            'duration_interval' => BillingInterval::Month,
            'duration_count' => 1,
        ];
    }

    /**
     * Indicate this is the schedule's terminal ("forever") step.
     */
    public function terminal(): static
    {
        return $this->state(fn (array $attributes) => [
            'duration_interval' => null,
            'duration_count' => null,
        ]);
    }
}
