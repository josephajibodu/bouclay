<?php

namespace App\Actions\Catalog;

use App\Enums\CatalogStatus;
use App\Enums\PriceType;
use App\Enums\PricingModel;
use App\Enums\TrialDurationType;
use App\Models\Price;
use App\Models\TrialOffer;
use Illuminate\Support\Facades\DB;

class CreateTrialOffer
{
    /**
     * Create a free trial on a price.
     *
     * Maps the single "how long?" question the UI asks (CATALOG_DESIGN.md
     * §7.1) onto the full `trial_offers` shape: a hidden zero-amount price
     * carries the duration (billing_interval/billing_frequency), the trial
     * always runs for exactly one period of it (duration_iterations = 1),
     * and it never transitions to a different product for MVP.
     *
     * @param  array{duration_amount: int, duration_unit: string}  $data
     */
    public function handle(Price $transitionPrice, array $data): TrialOffer
    {
        return DB::transaction(function () use ($transitionPrice, $data) {
            $trialPrice = $transitionPrice->product->prices()->create([
                'team_id' => $transitionPrice->team_id,
                'name' => 'Trial — '.$transitionPrice->product->name,
                'type' => PriceType::Recurring,
                'pricing_model' => PricingModel::Standard,
                'unit_amount' => 0,
                'currency' => $transitionPrice->currency,
                'billing_interval' => $data['duration_unit'],
                'billing_frequency' => $data['duration_amount'],
                'status' => CatalogStatus::Active,
            ]);

            return TrialOffer::create([
                'team_id' => $transitionPrice->team_id,
                'name' => $transitionPrice->product->name.' free trial',
                'product_id' => $transitionPrice->product_id,
                'trial_price_id' => $trialPrice->id,
                'transition_to_different_product' => false,
                'transition_product_id' => $transitionPrice->product_id,
                'transition_price_id' => $transitionPrice->id,
                'duration_type' => TrialDurationType::Relative,
                'duration_iterations' => 1,
                'once_per_customer' => true,
                'active' => true,
            ]);
        });
    }
}
