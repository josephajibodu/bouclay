<?php

/*
|--------------------------------------------------------------------------
| SIM-03 — Mid-cycle decrease defers to next renewal
|--------------------------------------------------------------------------
|
| BILLING_SIMULATIONS.md SIM-03 (verdict as updated by GAP-2/3): a decrease
| on an already-paid period would yield a net credit with nowhere to land
| (no customer balance ledger in MVP), so decreases take effect at the next
| renewal via a scheduled_changes update row. Team subscription on
| price_seat_m, quantity 15 → 10 on day 20. Promoted in V2-3.
*/

it('writes a scheduled update row instead of prorating a decrease', function () {
    // scheduled_changes{action=update, payload:{subscription_item_id,
    // quantity:10}, effective_at=current_period_end, applied_at=null}.
})->todo();

it('creates no mid-cycle credit and no proration lines for the decrease', function () {
    // The already-paid cycle invoice is untouched; no invoice or line is
    // written at day 20.
})->todo();

it('keeps the item at the old quantity until the boundary', function () {
    // subscription_items.quantity stays 15 until effective_at.
})->todo();

it('bills the day-30 renewal at the reduced quantity with no proration lines', function () {
    // Worker applies the payload at effective_at, stamps applied_at; the
    // renewal invoice bills 10 × 100000 = 1000000.
})->todo();

it('lets the merchant delete the pending decrease before it applies', function () {
    // Pending update rows are surfaced on the subscription hub and deletable
    // until applied_at — cancelling a scheduled downgrade is a row delete.
})->todo();
