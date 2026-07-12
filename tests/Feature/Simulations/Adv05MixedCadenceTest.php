<?php

/*
|--------------------------------------------------------------------------
| ADV-05 — Two recurring items on different billing intervals (forbidden)
|--------------------------------------------------------------------------
|
| BILLING_SIMULATIONS.md ADV-05 / GAP-5 (locked): current_period_start/end
| live on the subscription — one renewal clock per subscription. Mixed
| cadence is rejected at create and item-update; multi-cadence customers
| hold multiple subscriptions (the `type` named slot). Promoted in V2-2.
*/

it('rejects creating a subscription mixing monthly and annual items', function () {
    // CreateSubscription throws a clear validation error when items differ
    // in billing_interval or billing_frequency.
})->todo();

it('rejects an item update that would introduce a second cadence', function () {
    // UpdateSubscriptionItem refuses a price swap onto a different
    // interval/frequency than the subscription's other items.
})->todo();

it('supports monthly and annual charges as two subscriptions on one customer', function () {
    // The same customer holds two subscriptions (distinct `type` slots),
    // each with its own clock — the sanctioned path.
})->todo();
