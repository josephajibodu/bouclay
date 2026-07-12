<?php

namespace App\Actions\Catalog;

use App\Enums\CatalogStatus;
use App\Models\Price;
use App\Models\PriceTier;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

class CreatePrice
{
    /**
     * Create a price on a product, optionally as a variant of one of the
     * product's plans (required for recurring prices — a plan-less price
     * can never be subscribed to, schema.md §3).
     *
     * Amounts arrive from the UI in major currency units (e.g. Naira, not
     * kobo) — see CATALOG_DESIGN.md §6.2 — and are converted to minor units
     * here, the only place that conversion happens.
     *
     * @param  array<string, mixed>  $data  validated StorePriceRequest payload
     */
    public function handle(Product $product, array $data): Price
    {
        return DB::transaction(function () use ($product, $data) {
            $price = $product->prices()->create([
                'team_id' => $product->team_id,
                'plan_id' => $data['plan_id'] ?? null,
                'name' => $data['name'] ?? null,
                'type' => $data['type'],
                'pricing_model' => $data['pricing_model'],
                'unit_amount' => isset($data['unit_amount']) ? $this->toMinorUnits($data['unit_amount']) : null,
                'currency' => $data['currency'],
                'billing_interval' => $data['type'] === 'recurring' ? $data['billing_interval'] : null,
                'billing_frequency' => $data['billing_frequency'] ?? 1,
                'tax_mode' => 'account',
                'status' => CatalogStatus::Active,
                'replaces_price_id' => $data['replaces_price_id'] ?? null,
                'version' => $data['version'] ?? 1,
                'trial_length' => $data['trial_length'] ?? null,
                'trial_unit' => isset($data['trial_length']) ? ($data['trial_unit'] ?? 'day') : null,
                'trial_requires_payment_info' => (bool) ($data['trial_requires_payment_info'] ?? false),
                'trial_once_per_customer' => (bool) ($data['trial_once_per_customer'] ?? true),
                // False only for auto-created phase charge targets — never
                // offered directly in any picker (schema.md §3).
                'purchasable' => (bool) ($data['purchasable'] ?? true),
                'custom_data' => $data['custom_data'] ?? null,
            ]);

            if (! empty($data['tiers'])) {
                foreach (array_values($data['tiers']) as $index => $tier) {
                    PriceTier::create([
                        'price_id' => $price->id,
                        'tier_index' => $index,
                        // `up_to` is a quantity threshold (e.g. "up to 100 seats"), not money — no minor-unit conversion.
                        'up_to' => $tier['up_to'] ?? null,
                        'unit_amount' => $this->toMinorUnits($tier['unit_amount']),
                        'flat_amount' => isset($tier['flat_amount']) ? $this->toMinorUnits($tier['flat_amount']) : null,
                    ]);
                }
            }

            return $price->load('tiers');
        });
    }

    private function toMinorUnits(int|float $majorAmount): int
    {
        return (int) round($majorAmount * 100);
    }
}
