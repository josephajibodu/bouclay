<?php

/*
|--------------------------------------------------------------------------
| SIM-02 — Mid-cycle upgrade with proration (quantity increase)
|--------------------------------------------------------------------------
|
| BILLING_SIMULATIONS.md SIM-02. A Team subscription on price_seat_m
| (₦1,000/seat/mo), 30-day cycle, quantity 10 → 15 on day 12 (18 days
| remaining). Proves the invoice ledger is the durable record — no temporal
| column on subscription_items. Promoted in V2-3.
*/

it('writes a subscription_update invoice with paired proration lines for the covered window', function () {
    // billing_reason=subscription_update; two proration=true lines, both
    // period_start=day12, period_end=day30:
    //   credit "Unused 10 seats":            −(10 × 100000 × 18/30) = −600000
    //   charge "15 seats, remainder":        +(15 × 100000 × 18/30) = +900000
})->todo();

it('charges the net proration amount now and updates the item quantity', function () {
    // Net charged now: +300000 (5 extra seats × 18/30);
    // subscription_items{quantity: 10→15}.
})->todo();

it('bills the next renewal at the full new quantity', function () {
    // Day-30 cycle invoice: 15 × 100000 = 1500000, no proration lines.
})->todo();

it('makes the seat history derivable from immutable invoice lines alone', function () {
    // "10 seats for 12 days, 15 for 18 days" reconstructs as
    // (full-period charge for 10) − (credit 10 × 18/30) + (charge 15 × 18/30),
    // every term an invoice_line with period_start/period_end.
})->todo();
