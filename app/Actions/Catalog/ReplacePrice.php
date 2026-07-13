<?php

namespace App\Actions\Catalog;

use App\Enums\CatalogStatus;
use App\Models\Price;
use App\Models\PriceTier;
use Illuminate\Support\Facades\DB;

/**
 * The only legal "edit" for a price that subscriptions or invoices already
 * reference (schema.md §3): create a successor row (`replaces_price_id` →
 * the original, version+1), archive the original, and repoint the catalog —
 * existing subscribers stay grandfathered on the original row forever.
 *
 * The editing UI reads as "edit"; this is what actually executes.
 */
class ReplacePrice
{
    /**
     * @param  array<string, mixed>  $changes  the edited fields, same shape and
     *                                         major-unit amounts as an update
     *                                         payload; anything omitted carries
     *                                         over from the original unchanged
     */
    public function handle(Price $original, array $changes): Price
    {
        return DB::transaction(function () use ($original, $changes) {
            $successor = $this->createSuccessor($original, $changes);

            $this->copyTiers($original, $successor, $changes);
            $this->copyPhases($original, $successor);

            // The one in-place mutation the invariant allows: archiving.
            $original->update(['status' => CatalogStatus::Archived]);

            // A durable hosted checkout URL keeps working after an edit —
            // it now sells the successor (new signups always get the
            // current version; SIM-04).
            $original->paymentLink?->update(['price_id' => $successor->id]);

            return $successor->load('tiers');
        });
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function createSuccessor(Price $original, array $changes): Price
    {
        $type = $changes['type'] ?? $original->type->value;

        $unitAmount = array_key_exists('unit_amount', $changes)
            ? (isset($changes['unit_amount']) ? $this->toMinorUnits($changes['unit_amount']) : null)
            : $original->unit_amount;

        $trialLength = array_key_exists('trial_length', $changes)
            ? $changes['trial_length']
            : $original->trial_length;

        $originalTrialUnit = $original->trial_unit?->value;

        return Price::create([
            'team_id' => $original->team_id,
            'product_id' => $original->product_id,
            'plan_id' => array_key_exists('plan_id', $changes) ? $changes['plan_id'] : $original->plan_id,
            'name' => array_key_exists('name', $changes) ? $changes['name'] : $original->name,
            'type' => $type,
            'pricing_model' => $changes['pricing_model'] ?? $original->pricing_model->value,
            'unit_amount' => $unitAmount,
            'currency' => $changes['currency'] ?? $original->currency,
            'billing_interval' => $type === 'recurring'
                ? ($changes['billing_interval'] ?? $original->billing_interval?->value)
                : null,
            'billing_frequency' => $changes['billing_frequency'] ?? $original->billing_frequency,
            'package_size' => $original->package_size,
            'tax_mode' => $original->tax_mode->value,
            'status' => CatalogStatus::Active,
            'replaces_price_id' => $original->id,
            // A display label for "v2 of this price" — bumped on the
            // successor, never used to UPDATE an existing row.
            'version' => $original->version + 1,
            'trial_length' => $trialLength,
            'trial_unit' => $trialLength !== null
                ? (array_key_exists('trial_unit', $changes) ? $changes['trial_unit'] : ($originalTrialUnit ?? 'day'))
                : null,
            'trial_requires_payment_info' => (bool) ($changes['trial_requires_payment_info'] ?? $original->trial_requires_payment_info),
            'trial_once_per_customer' => (bool) ($changes['trial_once_per_customer'] ?? $original->trial_once_per_customer),
            'purchasable' => $original->purchasable,
            'custom_data' => array_key_exists('custom_data', $changes) ? $changes['custom_data'] : $original->custom_data,
        ]);
    }

    /**
     * New tiers (major units) when the edit rewrites them; otherwise the
     * original's tier rows verbatim.
     *
     * @param  array<string, mixed>  $changes
     */
    private function copyTiers(Price $original, Price $successor, array $changes): void
    {
        if (array_key_exists('tiers', $changes)) {
            foreach (array_values($changes['tiers'] ?? []) as $index => $tier) {
                PriceTier::create([
                    'price_id' => $successor->id,
                    'tier_index' => $index,
                    'up_to' => $tier['up_to'] ?? null,
                    'unit_amount' => $this->toMinorUnits($tier['unit_amount']),
                    'flat_amount' => isset($tier['flat_amount']) ? $this->toMinorUnits($tier['flat_amount']) : null,
                ]);
            }

            return;
        }

        foreach ($original->tiers as $tier) {
            PriceTier::create([
                'price_id' => $successor->id,
                'tier_index' => $tier->tier_index,
                'up_to' => $tier->up_to,
                'unit_amount' => $tier->unit_amount,
                'flat_amount' => $tier->flat_amount,
            ]);
        }
    }

    /**
     * A successor keeps the original's phase schedule — the phases point at
     * the same charge prices; only the home row is new. (Rewriting phases
     * is its own edit via SyncPricePhases on the successor.)
     */
    private function copyPhases(Price $original, Price $successor): void
    {
        foreach ($original->phases as $phase) {
            $successor->phases()->create([
                'sequence' => $phase->sequence,
                'charge_price_id' => $phase->charge_price_id,
                'duration_interval' => $phase->duration_interval->value,
                'duration_count' => $phase->duration_count,
            ]);
        }
    }

    private function toMinorUnits(int|float $majorAmount): int
    {
        return (int) round($majorAmount * 100);
    }
}
