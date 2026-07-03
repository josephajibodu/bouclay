<?php

namespace Database\Factories;

use App\Enums\TrialDurationType;
use App\Models\Price;
use App\Models\Product;
use App\Models\Team;
use App\Models\TrialOffer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrialOffer>
 */
class TrialOfferFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * Defaults to a 14-day free trial on a freshly-made product with its
     * own regular price, matching the "one price, no transition" shape
     * every trial takes for MVP (CATALOG_DESIGN.md §7.1).
     *
     * Attributes below are closures rather than factory instances so that
     * overriding `team_id` (e.g. `TrialOffer::factory()->create(['team_id' => $team->id])`)
     * cascades into the nested product/prices instead of spawning an
     * unrelated team — Laravel resolves each closure against the
     * already-resolved attributes, in declaration order.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'name' => '14-day free trial',
            'product_id' => fn (array $attributes) => Product::factory()->create([
                'team_id' => $attributes['team_id'],
            ])->id,
            'trial_price_id' => fn (array $attributes) => Price::factory()->free()->create([
                'team_id' => $attributes['team_id'],
                'product_id' => $attributes['product_id'],
                'billing_interval' => 'day',
                'billing_frequency' => 14,
            ])->id,
            'transition_to_different_product' => false,
            'transition_product_id' => null,
            'transition_price_id' => fn (array $attributes) => Price::factory()->create([
                'team_id' => $attributes['team_id'],
                'product_id' => $attributes['product_id'],
            ])->id,
            'duration_type' => TrialDurationType::Relative,
            'duration_iterations' => 1,
            'duration_ends_at' => null,
            'once_per_customer' => true,
            'active' => true,
            'custom_data' => null,
        ];
    }
}
