<?php

namespace App\Actions\Subscriptions;

use App\Enums\CatalogStatus;
use App\Models\Price;
use App\Models\PricingJourney;
use App\Models\Product;
use App\Models\Team;

/**
 * The catalog data any "create subscription" drawer needs — shared by the
 * Subscriptions list (its own "New subscription" drawer) and the customer
 * hub (its "Create subscription" action), so the two never drift apart.
 *
 * V2: only plan-bearing, purchasable, active recurring prices under an
 * active plan are offered (schema.md §3 — the draft/archived-plan rule).
 * A price is no longer intrinsically "phased" — a merchant picks either a
 * flat price or an active Pricing Journey for the base plan line
 * (schema.md §3/§5); `pricingJourneys` is offered alongside `prices` per
 * product for that choice.
 */
class BuildSubscriptionCreateOptions
{
    /**
     * @return array{products: array<int, array<string, mixed>>}
     */
    public function handle(Team $team): array
    {
        return [
            'products' => $this->productOptions($team),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function productOptions(Team $team): array
    {
        $products = $team->products()
            ->where('status', CatalogStatus::Active)
            ->with([
                'prices' => fn ($query) => $query->purchasableForNewSubscriptions()->with('plan'),
                'pricingJourneys' => fn ($query) => $query->where('status', CatalogStatus::Active)->with('steps.price'),
            ])
            ->orderBy('name')
            ->get()
            ->filter(fn (Product $product) => $product->prices->isNotEmpty() || $product->pricingJourneys->isNotEmpty());

        return array_map(fn (Product $product): array => [
            'id' => $product->id,
            'name' => $product->name,
            'prices' => array_map(fn (Price $price): array => [
                'id' => $price->id,
                'label' => $price->toPickerLabel(),
                'planName' => $price->plan?->name,
                'unitAmount' => $price->unit_amount !== null ? $price->unit_amount / 100 : null,
                'currency' => $price->currency,
                'billingInterval' => $price->billing_interval?->value,
                // "Add trial" is picking a trial-bearing price
                // (IMPLEMENTATION_V2 §V2-2) — the picker labels it and the
                // preview zeroes day-0 for a free one. A multi-step ramp is
                // no longer a price property — see `pricingJourneys` below.
                'trial' => $price->trialSummary(),
            ], $product->prices->all()),
            'pricingJourneys' => $product->pricingJourneys
                ->filter(fn (PricingJourney $journey): bool => $journey->steps->isNotEmpty())
                ->map(fn (PricingJourney $journey): array => [
                    'id' => $journey->id,
                    'name' => $journey->name,
                    'description' => $journey->describe(),
                    'currency' => $journey->steps->first()->price->currency,
                ])
                ->values()
                ->all(),
        ], array_values($products->all()));
    }
}
