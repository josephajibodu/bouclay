<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Discount;
use App\Models\DiscountRedemption;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DiscountRedemption>
 */
class DiscountRedemptionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * `remaining_intervals` mirrors what redemption snapshots from the
     * discount's duration (once → 1, repeating → N, forever → null).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'discount_id' => Discount::factory(),
            'subscription_id' => Subscription::factory(),
            'customer_id' => Customer::factory(),
            'remaining_intervals' => fn (array $attributes) => Discount::query()
                ->whereKey($attributes['discount_id'])
                ->first()
                ?->initialRemainingIntervals(),
            'applied_at' => now(),
            'last_applied_at' => null,
        ];
    }
}
