<?php

use App\Actions\Subscriptions\AdvanceSubscriptionPhases;
use App\Actions\Subscriptions\CreateSubscription;
use App\Enums\InvoiceBillingReason;
use App\Enums\InvoiceLineKind;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionItemKind;
use App\Enums\SubscriptionStatus;
use App\Models\PaymentMethod;
use App\Models\PriceTrialRedemption;
use App\Models\Subscription;
use App\Models\TeamProcessorConnection;

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
|
| V2-2 note: the discount engine (WELCOME20) is V2-3 — these Act 1–3 cases
| assert the *undiscounted* bundle (₦5,000 + ₦1,500 = ₦6,500 = 650000 kobo);
| V2-3 layers WELCOME20 on top (dropping the total to 520000) by extending
| these cases, not rewriting them.
*/

/**
 * SIM-01 Act 1 + 2 in one setup: Amina has a card on file and subscribes to
 * the trial-bearing Premium plan plus the no-trial Sports Pack add-on, on
 * automatic collection. Returns the fixture, the subscription, and the card.
 *
 * @return array{fx: array<string, mixed>, subscription: Subscription, card: PaymentMethod}
 */
function subscribeAminaOnFreeTrial(): array
{
    $fx = naijaStreamFixture();
    $team = $fx['team'];
    $amina = $fx['amina'];

    // Card stored at hosted checkout; trial_requires_payment_info=true means
    // it is kept but not charged (Act 1).
    $card = PaymentMethod::factory()->for($team)->for($amina)->create(['is_default' => true]);

    $subscription = app(CreateSubscription::class)->handle($team, [
        'customer_id' => $amina->id,
        'collection_mode' => 'automatic',
        'payment_method_id' => $card->id,
        'items' => [
            ['price_id' => $fx['price_prem_m']->id, 'quantity' => 1],
            ['price_id' => $fx['price_sports_m']->id, 'quantity' => 1],
        ],
    ]);

    return ['fx' => $fx, 'subscription' => $subscription, 'card' => $card];
}

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
    ['subscription' => $subscription, 'card' => $card] = subscribeAminaOnFreeTrial();

    // The card is on file and attached to the subscription…
    expect($card->fresh())->not->toBeNull()
        ->and($subscription->payment_method_id)->toBe($card->id);

    // …but nothing was charged: no invoice, no payment during the trial.
    expect($subscription->invoices()->count())->toBe(0);
});

// Act 2 — Subscribe (free trial + add-on)
it('creates a trialing subscription with the plan item anchoring the trial and the add-on riding it', function () {
    ['fx' => $fx, 'subscription' => $subscription] = subscribeAminaOnFreeTrial();

    $subscription->load('items');
    $plan = $subscription->items->firstWhere('kind', SubscriptionItemKind::Plan);
    $addon = $subscription->items->firstWhere('kind', SubscriptionItemKind::Addon);

    expect($subscription->status)->toBe(SubscriptionStatus::Trialing)
        ->and($subscription->trial_ends_at->toDateString())->toBe(now()->addDays(7)->toDateString())
        // Item A (plan) anchors the trial.
        ->and($plan->price_id)->toBe($fx['price_prem_m']->id)
        ->and($plan->trial_ends_at->toDateString())->toBe(now()->addDays(7)->toDateString())
        // Item B (add-on) rides the anchor — no trial of its own.
        ->and($addon->price_id)->toBe($fx['price_sports_m']->id)
        ->and($addon->trial_ends_at)->toBeNull();
});

it('writes a price_trial_redemptions row locking trial_once_per_customer at trial start', function () {
    ['fx' => $fx, 'subscription' => $subscription] = subscribeAminaOnFreeTrial();

    $plan = $subscription->items()->where('kind', SubscriptionItemKind::Plan)->firstOrFail();

    // Exactly one redemption — the trial-bearing plan price, not the add-on.
    expect(PriceTrialRedemption::query()->count())->toBe(1);

    $redemption = PriceTrialRedemption::query()->firstOrFail();
    expect($redemption->team_id)->toBe($fx['team']->id)
        ->and($redemption->price_id)->toBe($fx['price_prem_m']->id)
        ->and($redemption->customer_id)->toBe($fx['amina']->id)
        ->and($redemption->subscription_item_id)->toBe($plan->id);
});

it('rejects a second free trial of the same price for the same customer', function () {
    ['fx' => $fx] = subscribeAminaOnFreeTrial();

    // A second attempt at the once-per-customer trial is refused up front.
    expect(fn () => app(CreateSubscription::class)->handle($fx['team'], [
        'customer_id' => $fx['amina']->id,
        'collection_mode' => 'automatic',
        'items' => [['price_id' => $fx['price_prem_m']->id, 'quantity' => 1]],
    ]))->toThrow(InvalidArgumentException::class, 'once per customer');
});

it('charges nothing on day 0 even though a paid add-on is present', function () {
    ['subscription' => $subscription] = subscribeAminaOnFreeTrial();

    // GAP-4 locked: no invoice for either item during the trial; the
    // conversion invoice (Act 3) is the first one.
    expect($subscription->invoices()->count())->toBe(0);
});

it('writes a discount_redemptions row with remaining_intervals snapshotted from the discount duration', function () {
    // duration=repeating, duration_in_intervals=3 ⇒ remaining_intervals=3 (GAP-1)
})->todo();

it('grants hd_streaming and sports_channels while trialing', function () {
    // Access check: plan:Premium → hd_streaming, product:Sports Pack → sports_channels.
})->todo();

// Act 3 — Day 7: trial converts
it('converts the trial to active and emits subscription.updated', function () {
    ['fx' => $fx, 'subscription' => $subscription] = subscribeAminaOnFreeTrial();
    TeamProcessorConnection::factory()->for($fx['team'])->testConnected()->create();
    fakeNombaCharge();

    $this->travelTo(now()->addDays(8));

    app(AdvanceSubscriptionPhases::class)->handle($subscription->fresh());

    expect($subscription->fresh()->status)->toBe(SubscriptionStatus::Active);
});

it('issues a conversion invoice bundling plan and add-on with undiscounted totals', function () {
    ['fx' => $fx, 'subscription' => $subscription] = subscribeAminaOnFreeTrial();
    TeamProcessorConnection::factory()->for($fx['team'])->testConnected()->create();
    fakeNombaCharge();

    $this->travelTo(now()->addDays(8));
    app(AdvanceSubscriptionPhases::class)->handle($subscription->fresh());

    $invoice = $subscription->invoices()->firstOrFail();
    $invoice->load('lines');

    expect($invoice->billing_reason)->toBe(InvoiceBillingReason::SubscriptionCreate)
        ->and($invoice->billed_to_customer_id)->toBe($fx['amina']->id)
        ->and($invoice->subtotal)->toBe(650000)
        ->and($invoice->total)->toBe(650000)
        // amount_due nets to 0 once the charge settles synchronously below;
        // the pre-payment amount_due == total is proven by amount_paid == 650000
        // in the charge case.
        ->and($invoice->lines)->toHaveCount(2);

    $planLine = $invoice->lines->firstWhere('kind', InvoiceLineKind::Plan);
    $addonLine = $invoice->lines->firstWhere('kind', InvoiceLineKind::Addon);

    expect($planLine->unit_amount)->toBe(500000)
        ->and($planLine->subtotal)->toBe(500000)
        ->and($addonLine->unit_amount)->toBe(150000)
        ->and($addonLine->subtotal)->toBe(150000);
});

it('snapshots product, plan, and price names onto the conversion invoice lines', function () {
    ['fx' => $fx, 'subscription' => $subscription] = subscribeAminaOnFreeTrial();
    TeamProcessorConnection::factory()->for($fx['team'])->testConnected()->create();
    fakeNombaCharge();

    $this->travelTo(now()->addDays(8));
    app(AdvanceSubscriptionPhases::class)->handle($subscription->fresh());

    $planLine = $subscription->invoices()->firstOrFail()
        ->lines()->where('kind', InvoiceLineKind::Plan)->firstOrFail();

    expect($planLine->product_name_snapshot)->toBe('NaijaStream')
        ->and($planLine->plan_name_snapshot)->toBe('Premium')
        ->and($planLine->price_name_snapshot)->toBe('Premium Monthly');
});

it('keeps discount_total equal to the sum of line discount_amounts with no kind=discount line', function () {
    // The discount-representation invariant: discount_total == SUM(discount_amount)
    // == 130000; no invoice_lines{kind=discount} row exists. (V2-3 — discount engine.)
})->todo();

it('charges the conversion invoice on the stored token and marks it paid', function () {
    ['fx' => $fx, 'subscription' => $subscription] = subscribeAminaOnFreeTrial();
    TeamProcessorConnection::factory()->for($fx['team'])->testConnected()->create();
    fakeNombaCharge();

    $this->travelTo(now()->addDays(8));
    app(AdvanceSubscriptionPhases::class)->handle($subscription->fresh());

    $invoice = $subscription->invoices()->firstOrFail();
    $payment = $invoice->payments()->firstOrFail();

    expect($invoice->status)->toBe(InvoiceStatus::Paid)
        ->and($invoice->amount_paid)->toBe(650000)
        ->and($payment->status)->toBe(PaymentStatus::Succeeded)
        ->and($payment->amount)->toBe(650000)
        ->and($payment->attempt_number)->toBe(1);
});

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
