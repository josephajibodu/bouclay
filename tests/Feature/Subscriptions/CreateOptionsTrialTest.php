<?php

use App\Actions\Subscriptions\BuildSubscriptionCreateOptions;

/*
|--------------------------------------------------------------------------
| Create-subscription options expose trial metadata (V2-2 two-pane flow)
|--------------------------------------------------------------------------
|
| The create drawer's "Add trial = pick a trial-bearing price" affordance
| and its ₦0 day-0 preview key off the `trial` summary each price option
| carries. This proves the payload the frontend renders.
*/

it('surfaces a free-trial summary on a trial-bearing price and none on a plain add-on', function () {
    $fx = naijaStreamFixture();

    $options = app(BuildSubscriptionCreateOptions::class)->handle($fx['team']);

    $prices = collect($options['products'])->flatMap(fn (array $product) => $product['prices'])->keyBy('id');

    $premium = $prices[$fx['price_prem_m']->id];
    $sports = $prices[$fx['price_sports_m']->id];

    // Premium carries the 7-day free trial; the Sports Pack add-on carries none.
    expect($premium['trial'])->toBe(['label' => '7-day free trial', 'free' => true])
        ->and($sports['trial'])->toBeNull();
});
