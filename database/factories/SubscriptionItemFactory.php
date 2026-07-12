<?php

namespace Database\Factories;

use App\Enums\SubscriptionItemKind;
use App\Enums\SubscriptionItemStatus;
use App\Models\Price;
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
     * `plan_id` / `product_id` are denormalised from the item's price
     * (schema.md §6) — derived here so a test that hands the factory a
     * price gets a consistent row without spelling all three out.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'subscription_id' => Subscription::factory(),
            'price_id' => Price::factory(),
            'plan_id' => fn (array $attributes) => Price::query()->whereKey($attributes['price_id'])->first()?->plan_id,
            'product_id' => fn (array $attributes) => Price::query()->whereKey($attributes['price_id'])->first()?->product_id,
            'kind' => SubscriptionItemKind::Plan,
            'quantity' => 1,
            'status' => SubscriptionItemStatus::Active,
            'trial_ends_at' => null,
            'current_phase_sequence' => null,
        ];
    }

    /**
     * Indicate that this item is an add-on riding a base plan item.
     */
    public function addon(): static
    {
        return $this->state(fn (array $attributes) => [
            'kind' => SubscriptionItemKind::Addon,
        ]);
    }
}
