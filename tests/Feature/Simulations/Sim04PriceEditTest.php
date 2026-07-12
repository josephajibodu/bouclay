<?php

/*
|--------------------------------------------------------------------------
| SIM-04 — Merchant edits a live price (immutability proven)
|--------------------------------------------------------------------------
|
| BILLING_SIMULATIONS.md SIM-04. Premium Plus exists at ₦8,000
| (price_prem_plus_m) with Amina subscribed; the merchant raises it to
| ₦9,000. Editing a referenced price must create a successor row, never
| mutate. Promoted in V2-1 (ReplacePrice + saving guard).
*/

it('creates a successor row on edit instead of mutating the referenced price', function () {
    // New row: unit_amount=900000, replaces_price_id=price_prem_plus_m,
    // version=2, status=active. The original row's financial fields are
    // byte-identical to before the edit.
})->todo();

it('archives the superseded price', function () {
    // Old row: status → archived — the only field that changed.
})->todo();

it('keeps existing subscribers grandfathered on the original price', function () {
    // Amina's subscription_items.price_id still points at price_prem_plus_m
    // (₦8,000) and her renewals keep billing 800000.
})->todo();

it('leaves historical invoices unchanged via the immutable row and name snapshots', function () {
    // Past invoice_lines still read ₦8,000 through price_id AND their
    // price_name_snapshot, so a later rename can't rewrite history either.
})->todo();

it('offers only the successor price to new signups', function () {
    // Pickers/payment links list price_prem_plus_m_v2 (₦9,000); the archived
    // original is gone from purchasable listings.
})->todo();

it('keeps the price lineage walkable through replaces_price_id', function () {
    // v2 → replaces_price_id → v1; a second edit chains v3 → v2 → v1.
})->todo();

it('blocks direct mutation of frozen columns on a referenced price', function () {
    // The Price::saving guard throws when a financially-referenced row's
    // unit_amount/currency/interval is updated in place (V2-1 exit).
})->todo();
