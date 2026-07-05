<?php

namespace App\Actions\Subscriptions;

use App\Enums\CatalogStatus;
use App\Enums\PriceType;
use App\Models\Price;
use App\Models\Product;
use App\Models\Team;
use App\Models\TrialOffer;

/**
 * The catalog data any "create subscription" drawer needs — shared by the
 * Subscriptions list (its own "New subscription" drawer) and the customer
 * hub (its "Create subscription" action), so the two never drift apart.
 */
class BuildSubscriptionCreateOptions
{
    /**
     * @return array{products: array<int, array<string, mixed>>, trialOffers: array<int, array<string, mixed>>}
     */
    public function handle(Team $team): array
    {
        return [
            'products' => $this->productOptions($team),
            'trialOffers' => $this->trialOfferOptions($team),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function productOptions(Team $team): array
    {
        $products = $team->products()
            ->where('status', CatalogStatus::Active)
            ->with(['prices' => fn ($query) => $query->where('type', PriceType::Recurring)->where('status', CatalogStatus::Active)])
            ->orderBy('name')
            ->get()
            ->filter(fn (Product $product) => $product->prices->isNotEmpty());

        return array_map(fn (Product $product): array => [
            'id' => $product->id,
            'name' => $product->name,
            'prices' => array_map(fn (Price $price): array => [
                'id' => $price->id,
                'label' => $price->toPickerLabel(),
                'unitAmount' => $price->unit_amount !== null ? $price->unit_amount / 100 : null,
                'currency' => $price->currency,
                'billingInterval' => $price->billing_interval?->value,
            ], $product->prices->all()),
        ], array_values($products->all()));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function trialOfferOptions(Team $team): array
    {
        $offers = $team->trialOffers()
            ->where('active', true)
            ->with(['product', 'trialPrice', 'transitionPrice'])
            ->orderBy('name')
            ->get();

        return array_map(fn (TrialOffer $offer): array => [
            'id' => $offer->id,
            'name' => $offer->name,
            'product' => ['id' => $offer->product->id, 'name' => $offer->product->name],
            'trialPrice' => [
                'label' => $offer->trialPrice->toPickerLabel(),
                'isFree' => ($offer->trialPrice->unit_amount ?? 0) === 0,
                'unitAmount' => $offer->trialPrice->unit_amount !== null ? $offer->trialPrice->unit_amount / 100 : null,
                'currency' => $offer->trialPrice->currency,
            ],
            'transitionPrice' => [
                'label' => $offer->transitionPrice->toPickerLabel(),
                'unitAmount' => $offer->transitionPrice->unit_amount !== null ? $offer->transitionPrice->unit_amount / 100 : null,
                'currency' => $offer->transitionPrice->currency,
            ],
            'durationIterations' => $offer->duration_iterations,
        ], $offers->all());
    }
}
