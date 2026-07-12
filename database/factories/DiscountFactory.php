<?php

namespace Database\Factories;

use App\Enums\DiscountDuration;
use App\Enums\DiscountType;
use App\Models\Discount;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Discount>
 */
class DiscountFactory extends Factory
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
            'code' => strtoupper(fake()->unique()->bothify('PROMO##??')),
            'type' => DiscountType::Percentage,
            'amount' => null,
            'percentage' => '10.00',
            'currency' => null,
            'duration' => DiscountDuration::Once,
            'duration_in_intervals' => null,
            'max_redemptions' => null,
            'times_redeemed' => 0,
            'eligible_plan_ids' => null,
            'eligible_price_ids' => null,
            'starts_at' => null,
            'expires_at' => null,
            'active' => true,
        ];
    }

    /**
     * A flat (fixed minor-unit) discount.
     */
    public function flat(int $amount = 100000, string $currency = 'NGN'): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => DiscountType::Flat,
            'amount' => $amount,
            'percentage' => null,
            'currency' => $currency,
        ]);
    }

    /**
     * A repeating discount applied for N billing intervals.
     */
    public function repeating(int $intervals = 3): static
    {
        return $this->state(fn (array $attributes) => [
            'duration' => DiscountDuration::Repeating,
            'duration_in_intervals' => $intervals,
        ]);
    }

    /**
     * A discount applied on every cycle, forever.
     */
    public function forever(): static
    {
        return $this->state(fn (array $attributes) => [
            'duration' => DiscountDuration::Forever,
            'duration_in_intervals' => null,
        ]);
    }
}
