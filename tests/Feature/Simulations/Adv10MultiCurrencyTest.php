<?php

/*
|--------------------------------------------------------------------------
| ADV-10 — Same customer, two subscriptions, different currencies
|--------------------------------------------------------------------------
|
| BILLING_SIMULATIONS.md ADV-10 (a strength): currency is per-subscription
| and per-invoice; nothing forces a customer-level currency. The only
| external constraint is whether the processor can charge that card in
| that currency. Promoted in V2-2.
*/

it('lets one customer hold an NGN and a USD subscription side by side', function () {
    // Two subscriptions{currency: NGN / USD} on one customer, each billing
    // its own currency; customers.currency is a default, not a constraint.
})->todo();

it('keeps every invoice single-currency, matching its subscription', function () {
    // Each cycle invoice inherits its subscription's currency; no invoice
    // ever mixes currencies across lines (schema.md money rule).
})->todo();

it('rejects mixing currencies within one subscription cart', function () {
    // CreateSubscription refuses an NGN price and a USD price on the same
    // subscription — single-currency for life.
})->todo();
