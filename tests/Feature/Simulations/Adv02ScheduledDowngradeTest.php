<?php

/*
|--------------------------------------------------------------------------
| ADV-02 — Downgrade / quantity change scheduled for next renewal
|--------------------------------------------------------------------------
|
| BILLING_SIMULATIONS.md ADV-02 / GAP-2 (resolved): a downgrade effective
| at next renewal lives in scheduled_changes{action=update} with an item
| payload. Promoted in V2-3.
*/

it('writes an update row with the target item state in its payload', function () {
    // scheduled_changes{action=update, effective_at=current_period_end,
    // payload:{subscription_item_id, price_id?, plan_id?, quantity?, remove?}}.
})->todo();

it('writes one row per item change sharing effective_at for multi-item downgrades', function () {
    // A two-item change is two rows, same effective_at, applied atomically
    // at the boundary.
})->todo();

it('applies the payload at the boundary and marks the row applied', function () {
    // subscriptions:apply-scheduled-changes swaps the item to the payload
    // state at effective_at and stamps applied_at (audit trail).
})->todo();

it('surfaces pending downgrades on the subscription hub until applied', function () {
    // "Scheduled: 15 → 10 seats at next renewal" renders while
    // applied_at is null.
})->todo();
