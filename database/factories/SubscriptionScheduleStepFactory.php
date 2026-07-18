<?php

namespace Database\Factories;

use App\Models\Price;
use App\Models\SubscriptionSchedule;
use App\Models\SubscriptionScheduleStep;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<SubscriptionScheduleStep>
 */
class SubscriptionScheduleStepFactory extends Factory
{
    protected $model = SubscriptionScheduleStep::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'schedule_id' => SubscriptionSchedule::factory(),
            'sequence' => 0,
            'price_id' => Price::factory()->phaseOnly(),
            'quantity' => 1,
            'starts_at' => Carbon::now(),
            'ends_at' => Carbon::now()->addMonthNoOverflow(),
        ];
    }

    /**
     * Indicate this is the schedule's terminal ("forever") step.
     */
    public function terminal(): static
    {
        return $this->state(fn (array $attributes) => [
            'ends_at' => null,
        ]);
    }
}
