<?php

/*
|--------------------------------------------------------------------------
| ADV-06 — Switch payment method while in dunning
|--------------------------------------------------------------------------
|
| BILLING_SIMULATIONS.md ADV-06: updating subscription.payment_method_id
| mid-dunning re-points the retry worker at the new card. Promoted in V2-4.
*/

it('lets a past_due subscription swap to a different stored card', function () {
    // subscriptions{payment_method_id → new card} while status=past_due.
})->todo();

it('retries the open invoice on the new card and recovers on success', function () {
    // The next dunning attempt charges the swapped token; on success the
    // invoice pays and the subscription recovers past_due → active.
})->todo();

it('keeps charging through the gateway that minted the new token', function () {
    // Tokens are gateway-bound (schema.md routing rule): the retry routes
    // via payment_methods.processor, not the team default.
})->todo();
