<?php

/*
|--------------------------------------------------------------------------
| SIM-01 — Happy path: free-trial signup → convert → renew → cancel
|--------------------------------------------------------------------------
|
| BILLING_SIMULATIONS.md SIM-01. The baseline lifecycle against the shared
| NaijaStream fixture ({@see naijaStreamFixture()}): Amina takes the
| ₦5,000/mo Premium price (7-day card-required trial), the ₦1,500/mo
| Sports Pack add-on, and WELCOME20 (20% × 3 intervals).
|
| Cases are scaffolded as todos in V2-0 and promoted phase by phase:
| Acts 1–3 in V2-2, Act 4 + discount exhaustion in V2-3, Acts 5–6 in V2-4,
| access checks in V2-5 (IMPLEMENTATION_V2 §3 exit criteria).
*/

// Shared fixture — the one non-todo case in V2-0: the builder every
// simulation relies on must produce the documented catalog.
it('builds the NaijaStream fixture exactly as BILLING_SIMULATIONS.md specifies', function () {
    $fx = naijaStreamFixture();

    expect($fx['price_prem_m']->unit_amount)->toBe(500000)
        ->and($fx['price_prem_m']->trial_length)->toBe(7)
        ->and($fx['price_prem_m']->trial_unit->value)->toBe('day')
        ->and($fx['price_prem_m']->trial_requires_payment_info)->toBeTrue()
        ->and($fx['price_prem_m']->plan_id)->toBe($fx['premium']->id)
        ->and($fx['price_sports_m']->unit_amount)->toBe(150000)
        ->and($fx['price_seat_m']->unit_amount)->toBe(100000)
        ->and($fx['welcome20']->percentage)->toBe('20.00')
        ->and($fx['welcome20']->duration->value)->toBe('repeating')
        ->and($fx['welcome20']->duration_in_intervals)->toBe(3)
        ->and($fx['welcome20']->eligible_plan_ids)->toBe([$fx['premium']->id])
        ->and($fx['hdStreaming']->grants()->first()->grantor_type)->toBe('plan')
        ->and($fx['sportsChannels']->grants()->first()->grantor_type)->toBe('product')
        ->and($fx['amina']->name)->toBe('Amina');
});

// Act 1 — Customer + card
it('stores a tokenized card without charging when the trial requires payment info', function () {
    // trial_requires_payment_info=true ⇒ payment_methods row written,
    // zero payments rows, event payment_method.added emitted.
})->todo();

// Act 2 — Subscribe (free trial + add-on + discount)
it('creates a trialing subscription with the plan item anchoring the trial and the add-on riding it', function () {
    // subscriptions{status=trialing, trial_ends_at=+7d, discount_id=WELCOME20}
    // item A {price_prem_m, kind=plan, trial_ends_at=+7d}
    // item B {price_sports_m, kind=addon, trial_ends_at=null}
})->todo();

it('writes a price_trial_redemptions row locking trial_once_per_customer at trial start', function () {
    // price_trial_redemptions{team, price=price_prem_m, customer=Amina, subscription_item=A}
})->todo();

it('writes a discount_redemptions row with remaining_intervals snapshotted from the discount duration', function () {
    // duration=repeating, duration_in_intervals=3 ⇒ remaining_intervals=3 (GAP-1)
})->todo();

it('charges nothing on day 0 even though a paid add-on is present', function () {
    // GAP-4 locked: no invoice is generated for either item during the trial;
    // the conversion invoice (Act 3) is the first invoice.
})->todo();

it('grants hd_streaming and sports_channels while trialing', function () {
    // Access check: plan:Premium → hd_streaming, product:Sports Pack → sports_channels.
})->todo();

// Act 3 — Day 7: trial converts
it('converts the trial to active and emits subscription.updated', function () {
    // Worker flips trialing → active at trial_ends_at.
})->todo();

it('issues a conversion invoice bundling plan and add-on with exact discounted totals', function () {
    // billing_reason=subscription_create, billed_to_customer_id=Amina
    // line plan:  unit_amount=500000, subtotal=500000, discount_amount=100000, total=400000
    // line addon: unit_amount=150000, subtotal=150000, discount_amount=30000,  total=120000
    // invoice: subtotal=650000, discount_total=130000, tax_total=0, total=520000, amount_due=520000
})->todo();

it('snapshots product, plan, and price names onto the conversion invoice lines', function () {
    // product_name_snapshot="NaijaStream", plan_name_snapshot="Premium",
    // price_name_snapshot="Premium Monthly".
})->todo();

it('keeps discount_total equal to the sum of line discount_amounts with no kind=discount line', function () {
    // The discount-representation invariant: discount_total == SUM(discount_amount)
    // == 130000; no invoice_lines{kind=discount} row exists.
})->todo();

it('charges the conversion invoice on the stored token and marks it paid', function () {
    // payments{status=succeeded, amount=520000, attempt_number=1};
    // invoices{status=paid, amount_paid=520000}; event invoice.paid.
})->todo();

// Act 4 — Month-2 renewal
it('bills the month-2 renewal at the same discounted total while WELCOME20 has intervals left', function () {
    // billing_reason=subscription_cycle, same two lines, total=520000
    // (interval 2 of 3); remaining_intervals decrements.
})->todo();

it('stops applying WELCOME20 after its third interval', function () {
    // Interval 4 bills the undiscounted 650000; remaining_intervals=0,
    // last_applied_at stamped on interval 3 (GAP-1 resolution).
})->todo();

// Act 5 — Month-4 renewal fails → dunning → recover
it('records every charge attempt as its own payments row against one invoice', function () {
    // Exactly 3 payments rows (failed, failed, succeeded) sharing one invoice_id.
})->todo();

it('moves the subscription to past_due on decline and recovers it when a retry succeeds', function () {
    // active → past_due (markPastDue), invoice stays open, events
    // invoice.payment_failed + subscription.updated; then past_due → active
    // (recover), invoice paid, amount_paid == total.
})->todo();

// Act 6 — Partial refund
it('records a partial refund as its own row without overwriting the charge', function () {
    // refunds{payment_id, invoice_id, amount=200000, status=succeeded};
    // refunds.amount <= payments.amount; source payments{status→refunded}.
})->todo();

// Act 7 — Cancel at period end
it('schedules cancellation at period end while status stays active', function () {
    // scheduled_changes{action=cancel, effective_at=current_period_end,
    // applied_at=null}; canceled_at set but status stays active — dashboards
    // read status, not canceled_at.
})->todo();

it('applies the scheduled cancel at the boundary and stamps applied_at', function () {
    // Worker: subscriptions{status=canceled, ends_at}; scheduled_changes
    // {applied_at=now}; event subscription.updated.
})->todo();

it('revokes all entitlements after the subscription ends', function () {
    // Access check returns nothing after ends_at — HD + sports revoked.
})->todo();
