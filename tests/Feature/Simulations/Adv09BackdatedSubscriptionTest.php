<?php

/*
|--------------------------------------------------------------------------
| ADV-09 — Backdated subscription creation
|--------------------------------------------------------------------------
|
| BILLING_SIMULATIONS.md ADV-09: timestamps store fine; catch-up invoicing
| is worker logic. Promoted alongside the renewal worker hardening (V2-8
| idempotency/replay).
*/

it('stores a backdated current_period_start faithfully', function () {
    // A subscription created with period_start in the past persists exactly
    // as given — no clamping to now().
})->todo();

it('catches up overdue periods on the next renewal worker run', function () {
    // bill-renewals picks up a subscription whose current_period_end is
    // already in the past and issues the due cycle invoice.
})->todo();

it('remains idempotent when the catch-up run is repeated', function () {
    // Running the worker twice over the same overdue boundary produces one
    // invoice, not two (V2-8 double-run guarantee).
})->todo();
