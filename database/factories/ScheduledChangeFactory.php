<?php

namespace Database\Factories;

use App\Enums\ScheduledChangeAction;
use App\Models\ScheduledChange;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<ScheduledChange>
 */
class ScheduledChangeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'subscription_id' => Subscription::factory(),
            'action' => ScheduledChangeAction::Cancel,
            'effective_at' => Carbon::now()->addMonth(),
            'payload' => null,
            'applied_at' => null,
        ];
    }
}
