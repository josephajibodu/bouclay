<?php

namespace Database\Factories;

use App\Enums\ScheduleEndBehavior;
use App\Enums\SubscriptionScheduleStatus;
use App\Models\Subscription;
use App\Models\SubscriptionItem;
use App\Models\SubscriptionSchedule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SubscriptionSchedule>
 */
class SubscriptionScheduleFactory extends Factory
{
    protected $model = SubscriptionSchedule::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'subscription_id' => Subscription::factory(),
            'subscription_item_id' => SubscriptionItem::factory(),
            'price_phases_id' => null,
            'end_behavior' => ScheduleEndBehavior::Release,
            'status' => SubscriptionScheduleStatus::Active,
            'completed_at' => null,
        ];
    }

    /**
     * Indicate the schedule has finished and collapsed to flat billing.
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SubscriptionScheduleStatus::Completed,
            'completed_at' => now(),
        ]);
    }

    /**
     * Indicate the schedule ended by cancelling the subscription.
     */
    public function canceled(): static
    {
        return $this->state(fn (array $attributes) => [
            'end_behavior' => ScheduleEndBehavior::Cancel,
            'status' => SubscriptionScheduleStatus::Canceled,
            'completed_at' => now(),
        ]);
    }
}
