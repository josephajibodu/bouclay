<?php

namespace Database\Factories;

use App\Enums\SubscriptionItemStatus;
use App\Models\Price;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\SubscriptionItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SubscriptionItem>
 */
class SubscriptionItemFactory extends Factory
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
            'price_id' => Price::factory(),
            'product_id' => Product::factory(),
            'quantity' => 1,
            'status' => SubscriptionItemStatus::Active,
        ];
    }
}
