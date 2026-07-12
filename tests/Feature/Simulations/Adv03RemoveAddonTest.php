<?php

/*
|--------------------------------------------------------------------------
| ADV-03 — Remove an add-on mid-cycle
|--------------------------------------------------------------------------
|
| BILLING_SIMULATIONS.md ADV-03 / GAP-3 (resolved by policy): removal takes
| effect at next renewal; no mid-cycle credit is ever created in MVP (there
| is no customer balance ledger to hold one). Promoted in V2-3.
*/

it('schedules add-on removal for the next renewal instead of removing it now', function () {
    // scheduled_changes{action=update, payload:{subscription_item_id,
    // remove:true}, effective_at=current_period_end}.
})->todo();

it('creates no mid-cycle credit for the removed add-on', function () {
    // The paid cycle invoice is untouched; no negative line, no refund row.
})->todo();

it('marks the item removed at the boundary and stops billing it', function () {
    // At effective_at: subscription_items{status=removed}; the renewal
    // invoice carries no line for the add-on.
})->todo();
