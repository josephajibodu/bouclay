<?php

/*
|--------------------------------------------------------------------------
| ADV-01 — Upgrade during a free trial (no money moved yet)
|--------------------------------------------------------------------------
|
| BILLING_SIMULATIONS.md ADV-01 / GAP-6 (locked): changes while trialing
| apply immediately with NO proration — no money has moved; the conversion
| invoice reflects the final composition. Promoted in V2-3.
*/

it('applies an item change immediately while trialing', function () {
    // Swapping the trialing item's price/quantity updates the row in place;
    // no scheduled_changes row is written.
})->todo();

it('creates no proration invoice for a change made during the trial', function () {
    // Zero invoices exist between signup and conversion, upgrade included.
})->todo();

it('bills the conversion invoice on the final post-upgrade composition', function () {
    // The subscription_create invoice at trial end reflects the upgraded
    // price/quantity, not the composition chosen at signup.
})->todo();
