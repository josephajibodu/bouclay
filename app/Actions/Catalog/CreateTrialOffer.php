<?php

namespace App\Actions\Catalog;

use App\Enums\TrialDurationType;
use App\Models\Product;
use App\Models\TrialOffer;

class CreateTrialOffer
{
    /**
     * Create a trial offer.
     *
     * `trial_price_id` and `transition_price_id` are real, user-chosen
     * prices — the trial isn't necessarily free, and the price it charges
     * during the trial is a normal catalog price like any other (see
     * CATALOG_DESIGN.md §7.1, revised). Duration comes from the trial
     * price's own billing interval × `duration_iterations` ("Repeat"),
     * matching Stripe's Trial Offer model this mirrors.
     *
     * @param  array{
     *     name: string,
     *     trial_price_id: int,
     *     transition_to_different_product: bool,
     *     transition_product_id: int|null,
     *     transition_price_id: int,
     *     duration_iterations: int,
     * }  $data
     */
    public function handle(Product $product, array $data): TrialOffer
    {
        return TrialOffer::create([
            'team_id' => $product->team_id,
            'name' => $data['name'],
            'product_id' => $product->id,
            'trial_price_id' => $data['trial_price_id'],
            'transition_to_different_product' => $data['transition_to_different_product'],
            'transition_product_id' => $data['transition_to_different_product']
                ? $data['transition_product_id']
                : $product->id,
            'transition_price_id' => $data['transition_price_id'],
            'duration_type' => TrialDurationType::Relative,
            'duration_iterations' => $data['duration_iterations'],
            'once_per_customer' => true,
            'active' => true,
        ]);
    }
}
