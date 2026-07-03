<?php

namespace Database\Factories;

use App\Enums\BillingInterval;
use App\Enums\CatalogStatus;
use App\Enums\PriceType;
use App\Enums\PricingModel;
use App\Enums\TaxMode;
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
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'product_id' => Product::factory(),
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
            'version' => 1,
            'custom_data' => null,
        ];
    }

    /**
     * Indicate that the price is a one-time (non-recurring) charge.
     */
    public function oneTime(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => PriceType::OneTime,
            'billing_interval' => null,
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
     * Indicate a zero-amount price — the shape used for hidden trial prices.
     */
    public function free(): static
    {
        return $this->state(fn (array $attributes) => [
            'unit_amount' => 0,
        ]);
    }
}
