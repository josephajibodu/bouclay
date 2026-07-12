<?php

namespace App\Actions\Catalog;

use App\Enums\PriceType;
use App\Exceptions\ImmutablePriceViolation;
use App\Models\Price;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Replace a price's phase schedule (schema.md §3) — the authoring seam for
 * paid multi-iteration trials, "transition to a different plan after
 * trial", and multi-step ramps. Each phase charges a real `prices` row;
 * a phase amount entered inline auto-creates that row with
 * `purchasable = false` so it never surfaces in a picker.
 *
 * Phases are part of the price's frozen financial shape: once the home
 * price is referenced by a subscription or invoice, the schedule can only
 * change by replacing the price.
 */
class SyncPricePhases
{
    public function __construct(private readonly CreatePrice $createPrice)
    {
        //
    }

    /**
     * @param  array<int, array{
     *     charge_price_id?: int|null,
     *     charge_price?: array{unit_amount: int|float, name?: string|null}|null,
     *     duration_interval: string,
     *     duration_count: int,
     * }>  $phases  ordered; index becomes the phase sequence. An empty array
     *              clears the schedule. Inline `charge_price` amounts are in
     *              major currency units, like every catalog form.
     */
    public function handle(Price $price, array $phases): Price
    {
        if ($price->hasBeenUsed()) {
            throw ImmutablePriceViolation::forColumns($price, ['phases']);
        }

        return DB::transaction(function () use ($price, $phases) {
            $price->phases()->delete();

            foreach (array_values($phases) as $sequence => $phase) {
                $chargePrice = $this->resolveChargePrice($price, $phase, $sequence);

                $price->phases()->create([
                    'sequence' => $sequence,
                    'charge_price_id' => $chargePrice->id,
                    'duration_interval' => $phase['duration_interval'],
                    'duration_count' => (int) $phase['duration_count'],
                ]);
            }

            return $price->load(['phases.chargePrice']);
        });
    }

    /**
     * @param  array<string, mixed>  $phase
     */
    private function resolveChargePrice(Price $price, array $phase, int $sequence): Price
    {
        if (isset($phase['charge_price_id'])) {
            /** @var Price $chargePrice */
            $chargePrice = Price::query()
                ->whereKey((int) $phase['charge_price_id'])
                ->where('team_id', $price->team_id)
                ->firstOrFail();

            if ($chargePrice->currency !== $price->currency) {
                throw new InvalidArgumentException(
                    "Phase {$sequence} charges a {$chargePrice->currency} price; the schedule's home price is {$price->currency} — one currency per price.",
                );
            }

            if ($chargePrice->type !== PriceType::Recurring) {
                throw new InvalidArgumentException(
                    "Phase {$sequence} must charge a recurring price — one-time prices have no billing cadence to phase through.",
                );
            }

            return $chargePrice;
        }

        if (! isset($phase['charge_price'])) {
            throw new InvalidArgumentException(
                "Phase {$sequence} needs either an existing charge price or an inline amount.",
            );
        }

        // Auto-created charge target: same plan, cadence, and currency as
        // the home price — only the amount differs. Hidden from every
        // picker via purchasable=false (schema.md §3).
        return $this->createPrice->handle($price->product, [
            'plan_id' => $price->plan_id,
            'name' => $phase['charge_price']['name']
                ?? trim(($price->name ?? 'Price').' · Phase '.($sequence + 1)),
            'type' => PriceType::Recurring->value,
            'pricing_model' => 'standard',
            'unit_amount' => $phase['charge_price']['unit_amount'],
            'currency' => $price->currency,
            'billing_interval' => $price->billing_interval?->value,
            'billing_frequency' => $price->billing_frequency,
            'purchasable' => false,
        ]);
    }
}
