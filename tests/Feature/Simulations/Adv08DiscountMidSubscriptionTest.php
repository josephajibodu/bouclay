<?php

/*
|--------------------------------------------------------------------------
| ADV-08 — Apply / remove a discount mid-subscription
|--------------------------------------------------------------------------
|
| BILLING_SIMULATIONS.md ADV-08: the single discount_id FK means no
| stacking; redemption state (remaining_intervals) is what survives a
| swap — re-hits GAP-1. Promoted in V2-3.
*/

it('applies a discount to an already-active subscription from the next cycle', function () {
    // Setting discount_id mid-cycle writes a discount_redemptions row; the
    // NEXT renewal invoice carries the line-level discount_amounts.
})->todo();

it('removes a discount mid-subscription without touching issued invoices', function () {
    // Clearing discount_id stops future application; past invoices keep
    // their discount_amount lines untouched.
})->todo();

it('does not stack discounts — assigning a new one replaces the old', function () {
    // One discount_id FK: the old redemption stops accruing, the new
    // discount gets its own redemption with fresh remaining_intervals.
})->todo();

it('honors eligible_price_ids as the complete eligibility list when set', function () {
    // eligible_price_ids wins outright over eligible_plan_ids — never
    // combined/intersected (schema.md §7).
})->todo();

it('enforces max_redemptions across customers', function () {
    // A discount at its max_redemptions cap rejects new redemptions with a
    // clear validation error; times_redeemed tracks the count.
})->todo();
