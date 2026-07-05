<?php

namespace Database\Factories;

use App\Enums\SubscriptionItemTrialStatus;
use App\Enums\TrialDurationType;
use App\Models\Customer;
use App\Models\Price;
use App\Models\SubscriptionItem;
use App\Models\SubscriptionItemTrial;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<SubscriptionItemTrial>
 */
class SubscriptionItemTrialFactory extends Factory
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
            'subscription_item_id' => SubscriptionItem::factory(),
            'trial_offer_id' => null,
            'customer_id' => Customer::factory(),
            'trial_price_id' => Price::factory()->free(),
            'transition_price_id' => Price::factory(),
            'duration_type' => TrialDurationType::Relative,
            'duration_iterations' => 1,
            'starts_at' => Carbon::now(),
            'ends_at' => Carbon::now()->addDays(14),
            'status' => SubscriptionItemTrialStatus::Active,
        ];
    }
}
