<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Price;
use App\Models\PriceTrialRedemption;
use App\Models\SubscriptionItem;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PriceTrialRedemption>
 */
class PriceTrialRedemptionFactory extends Factory
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
            'price_id' => Price::factory(),
            'customer_id' => Customer::factory(),
            'subscription_item_id' => SubscriptionItem::factory(),
            'redeemed_at' => now(),
        ];
    }
}
