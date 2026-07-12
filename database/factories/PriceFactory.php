<?php

namespace Database\Factories;

use App\Enums\BillingInterval;
use App\Enums\CatalogStatus;
use App\Enums\PriceType;
use App\Enums\PricingModel;
use App\Enums\TaxMode;
use App\Enums\TrialUnit;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Price>
 */
class PriceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * A recurring price defaults to plan-bearing — only plan-bearing prices
     * can be attached to subscriptions (schema.md §3), so the factory keeps
     * the common test path valid. The plan is created under the same
     * team/product the price resolves to.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'product_id' => Product::factory(),
            'plan_id' => fn (array $attributes) => Plan::factory()->create([
                'team_id' => $attributes['team_id'],
                'product_id' => $attributes['product_id'],
            ])->id,
            'name' => null,
            'type' => PriceType::Recurring,
            'pricing_model' => PricingModel::Standard,
            'unit_amount' => 1500000,
            'currency' => 'NGN',
            'billing_interval' => BillingInterval::Month,
            'billing_frequency' => 1,
            'package_size' => null,
            'tax_mode' => TaxMode::Account,
            'status' => CatalogStatus::Active,
            'replaces_price_id' => null,
            'version' => 1,
            'trial_length' => null,
            'trial_unit' => null,
            'trial_requires_payment_info' => false,
            'trial_once_per_customer' => true,
            'purchasable' => true,
            'custom_data' => null,
        ];
    }

    /**
     * Indicate that the price is a one-time (non-recurring) charge sold
     * directly off the product, with no plan involved.
     */
    public function oneTime(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => PriceType::OneTime,
            'billing_interval' => null,
            'plan_id' => null,
        ]);
    }

    /**
     * Indicate that the price is archived.
     */
    public function archived(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => CatalogStatus::Archived,
        ]);
    }

    /**
     * Indicate a zero-amount price — the shape used for phase-only trial
     * charge targets.
     */
    public function free(): static
    {
        return $this->state(fn (array $attributes) => [
            'unit_amount' => 0,
        ]);
    }

    /**
     * Attach a simple trial (schema.md §5) to the price.
     */
    public function withTrial(int $length = 7, TrialUnit $unit = TrialUnit::Day, bool $requiresPaymentInfo = true): static
    {
        return $this->state(fn (array $attributes) => [
            'trial_length' => $length,
            'trial_unit' => $unit,
            'trial_requires_payment_info' => $requiresPaymentInfo,
        ]);
    }

    /**
     * A phase-only price — exists solely as a price_phases charge target,
     * hidden from every picker.
     */
    public function phaseOnly(): static
    {
        return $this->state(fn (array $attributes) => [
            'purchasable' => false,
        ]);
    }
}
