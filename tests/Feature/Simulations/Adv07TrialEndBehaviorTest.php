<?php

/*
|--------------------------------------------------------------------------
| ADV-07 — Trial expires with no card, per trial_end_behavior
|--------------------------------------------------------------------------
|
| BILLING_SIMULATIONS.md ADV-07: the trickiest state path — each
| trial_end_behavior branch when the clock runs out with no payment method,
| including the late-pay → activate path. Promoted in V2-2.
*/

it('cancels the subscription at trial end when trial_end_behavior is cancel', function () {
    // trialing → canceled; no invoice created; entitlements revoked.
})->todo();

it('pauses the subscription at trial end when trial_end_behavior is pause', function () {
    // trialing → paused; no invoice; resumable once a card arrives.
})->todo();

it('opens an invoice at trial end when trial_end_behavior is create_invoice', function () {
    // trialing → conversion path: an open invoice is issued for the first
    // cycle even with no card on file (Stripe missing_payment_method).
})->todo();

it('activates the subscription when the open conversion invoice is paid late', function () {
    // create_invoice → open → paid 10 days later → subscription active;
    // period anchored per billing_cycle_anchor_on_trial_end.
})->todo();
