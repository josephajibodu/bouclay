<?php

/*
|--------------------------------------------------------------------------
| ADV-04 — Quantity increase AND decrease with proration
|--------------------------------------------------------------------------
|
| BILLING_SIMULATIONS.md ADV-04: the two mid-cycle directions follow
| different locked policies (GAP-6) — increases prorate now (SIM-02),
| decreases defer to period end (SIM-03). Promoted in V2-3.
*/

it('prorates and charges an increase immediately by default', function () {
    // proration_behavior defaults to `always` for increases: paired
    // proration lines now, net charged now.
})->todo();

it('defers a decrease to next cycle by default', function () {
    // proration_behavior defaults to `next_cycle` for decreases: a
    // scheduled_changes update row, no money movement now.
})->todo();

it('honors an explicit proration_behavior of none by applying now and billing nothing', function () {
    // Support/goodwill edits: item updates immediately, no proration
    // invoice, next cycle bills the new state.
})->todo();

it('nets an increase-then-decrease sequence per policy rather than mixing them', function () {
    // Day 12 increase prorates + charges; day 20 decrease writes the
    // scheduled row; day 30 renewal bills the final quantity with no
    // further proration lines.
})->todo();
