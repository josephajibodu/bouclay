<?php

namespace App\Actions\Subscriptions;

use App\Enums\CatalogStatus;
use App\Models\Price;
use App\Models\Product;
use App\Models\Team;

/**
 * The catalog data any "create subscription" drawer needs — shared by the
 * Subscriptions list (its own "New subscription" drawer) and the customer
 * hub (its "Create subscription" action), so the two never drift apart.
 *
 * V2: only plan-bearing, purchasable, active recurring prices under an
 * active plan are offered (schema.md §3 — the draft/archived-plan rule).
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
            ->with(['prices' => fn ($query) => $query
                ->purchasableForNewSubscriptions()
                ->with(['plan', 'phases.chargePrice']),
            ])
            ->orderBy('name')
            ->get()
            ->filter(fn (Product $product) => $product->prices->isNotEmpty());

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
                // "Add trial" in the two-pane flow is just picking a
                // trial-bearing or phased price (IMPLEMENTATION_V2 §V2-2) — the
                // picker labels it and the preview zeroes day-0 for a free one.
                'trial' => $price->trialSummary(),
            ], $product->prices->all()),
        ], array_values($products->all()));
    }
}
