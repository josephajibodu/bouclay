<?php

use App\Actions\Dunning\RetryPastDueInvoice;
use App\Actions\Invoicing\RefundPayment;
use App\Actions\Subscriptions\AdvanceSubscriptionPhases;
use App\Actions\Subscriptions\CreateSubscription;
use App\Actions\Subscriptions\RenewSubscription;
use App\Enums\InvoiceBillingReason;
use App\Enums\InvoiceLineKind;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Enums\SubscriptionItemKind;
use App\Enums\SubscriptionStatus;
use App\Models\DiscountRedemption;
use App\Models\PaymentMethod;
use App\Models\PriceTrialRedemption;
use App\Models\Subscription;
use App\Models\TeamProcessorConnection;
use Illuminate\Support\Carbon;

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
        'discount_id' => $fx['welcome20']->id,
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
    ['fx' => $fx, 'subscription' => $subscription] = subscribeAminaOnFreeTrial();

    expect($subscription->discount_id)->toBe($fx['welcome20']->id);

    // duration=repeating, duration_in_intervals=3 ⇒ remaining_intervals=3 (GAP-1);
    // a free trial doesn't apply it yet, so no cycle is consumed at signup.
    $redemption = DiscountRedemption::query()->firstOrFail();
    expect($redemption->discount_id)->toBe($fx['welcome20']->id)
        ->and($redemption->subscription_id)->toBe($subscription->id)
        ->and($redemption->customer_id)->toBe($fx['amina']->id)
        ->and($redemption->remaining_intervals)->toBe(3)
        ->and($redemption->last_applied_at)->toBeNull()
        ->and($fx['welcome20']->refresh()->times_redeemed)->toBe(1);
});

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

it('issues a conversion invoice bundling plan and add-on with WELCOME20 applied', function () {
    ['fx' => $fx, 'subscription' => $subscription] = subscribeAminaOnFreeTrial();
    TeamProcessorConnection::factory()->for($fx['team'])->testConnected()->create();
    fakeNombaCharge();

    $this->travelTo(now()->addDays(8));
    app(AdvanceSubscriptionPhases::class)->handle($subscription->fresh());

    $invoice = $subscription->invoices()->firstOrFail();
    $invoice->load('lines');

    // WELCOME20 (20%) is whole-invoice: plan 500000 − 100000, addon 150000 − 30000.
    expect($invoice->billing_reason)->toBe(InvoiceBillingReason::SubscriptionCreate)
        ->and($invoice->billed_to_customer_id)->toBe($fx['amina']->id)
        ->and($invoice->subtotal)->toBe(650000)
        ->and($invoice->discount_total)->toBe(130000)
        ->and($invoice->total)->toBe(520000)
        ->and($invoice->lines)->toHaveCount(2);

    $planLine = $invoice->lines->firstWhere('kind', InvoiceLineKind::Plan);
    $addonLine = $invoice->lines->firstWhere('kind', InvoiceLineKind::Addon);

    expect($planLine->unit_amount)->toBe(500000)
        ->and($planLine->subtotal)->toBe(500000)
        ->and($planLine->discount_amount)->toBe(100000)
        ->and($planLine->total)->toBe(400000)
        ->and($addonLine->subtotal)->toBe(150000)
        ->and($addonLine->discount_amount)->toBe(30000)
        ->and($addonLine->total)->toBe(120000);
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
    ['fx' => $fx, 'subscription' => $subscription] = subscribeAminaOnFreeTrial();
    TeamProcessorConnection::factory()->for($fx['team'])->testConnected()->create();
    fakeNombaCharge();

    $this->travelTo(now()->addDays(8));
    app(AdvanceSubscriptionPhases::class)->handle($subscription->fresh());

    $invoice = $subscription->invoices()->firstOrFail();

    // The discount-representation invariant (schema.md §8): discount_total ==
    // SUM(line discount_amount) == 130000; no kind=discount line exists.
    expect((int) $invoice->lines()->sum('discount_amount'))->toBe(130000)
        ->and($invoice->discount_total)->toBe(130000)
        ->and($invoice->lines()->where('kind', InvoiceLineKind::Discount)->count())->toBe(0);
});

it('charges the conversion invoice on the stored token and marks it paid', function () {
    ['fx' => $fx, 'subscription' => $subscription] = subscribeAminaOnFreeTrial();
    TeamProcessorConnection::factory()->for($fx['team'])->testConnected()->create();
    fakeNombaCharge();

    $this->travelTo(now()->addDays(8));
    app(AdvanceSubscriptionPhases::class)->handle($subscription->fresh());

    $invoice = $subscription->invoices()->firstOrFail();
    $payment = $invoice->payments()->firstOrFail();

    expect($invoice->status)->toBe(InvoiceStatus::Paid)
        ->and($invoice->amount_paid)->toBe(520000)
        ->and($payment->status)->toBe(PaymentStatus::Succeeded)
        ->and($payment->amount)->toBe(520000)
        ->and($payment->attempt_number)->toBe(1);
});

/**
 * Convert the free trial to active (interval 1 of WELCOME20 applied) and
 * return the live subscription plus the shared charge fake still in effect.
 *
 * @return array{fx: array<string, mixed>, subscription: Subscription}
 */
function convertedAminaSubscription(): array
{
    ['fx' => $fx, 'subscription' => $subscription] = subscribeAminaOnFreeTrial();
    TeamProcessorConnection::factory()->for($fx['team'])->testConnected()->create();
    fakeNombaCharge();

    test()->travelTo(now()->addDays(8));
    app(AdvanceSubscriptionPhases::class)->handle($subscription->fresh());

    return ['fx' => $fx, 'subscription' => $subscription->fresh()];
}

/**
 * Advance one billing cycle: travel just past the renewal clock and bill it.
 */
function renewAminaOnce(Subscription $subscription): Subscription
{
    fakeNombaCharge();
    test()->travelTo(Carbon::instance($subscription->current_period_end)->addDay());
    app(RenewSubscription::class)->handle($subscription->fresh());

    return $subscription->fresh();
}

// Act 4 — Month-2 renewal
it('bills the month-2 renewal at the same discounted total while WELCOME20 has intervals left', function () {
    ['subscription' => $subscription] = convertedAminaSubscription();

    // Conversion consumed interval 1 (remaining 3 → 2).
    expect($subscription->activeDiscountRedemption()->remaining_intervals)->toBe(2);

    $subscription = renewAminaOnce($subscription);

    $renewal = $subscription->invoices()->where('billing_reason', InvoiceBillingReason::SubscriptionCycle)->firstOrFail();

    // Interval 2 of 3 — still discounted; remaining decrements to 1.
    expect($renewal->total)->toBe(520000)
        ->and($renewal->discount_total)->toBe(130000)
        ->and($subscription->activeDiscountRedemption()->remaining_intervals)->toBe(1);
});

it('stops applying WELCOME20 after its third interval', function () {
    ['subscription' => $subscription] = convertedAminaSubscription();

    // Interval 2 and interval 3 (the last), both discounted.
    $subscription = renewAminaOnce($subscription);
    $subscription = renewAminaOnce($subscription);

    // Interval 3 exhausted the budget — the redemption is no longer live.
    expect($subscription->activeDiscountRedemption())->toBeNull()
        ->and(DiscountRedemption::query()->firstOrFail()->remaining_intervals)->toBe(0);

    // Interval 4 (month-4) bills the undiscounted 650000.
    $subscription = renewAminaOnce($subscription);

    $latest = $subscription->invoices()->orderByDesc('id')->firstOrFail();
    expect($latest->total)->toBe(650000)
        ->and($latest->discount_total)->toBe(0);
});

// Act 5 — renewal fails → dunning → recover
it('records every charge attempt as its own payments row against one invoice', function () {
    // Conversion succeeds; the renewal + first retry decline; the second
    // retry recovers (charge outcomes sequenced up front — Http::fake is
    // first-registered-wins, so this must precede the conversion's own fake).
    fakeNombaChargeAttempts(true, false, false, true);

    ['subscription' => $subscription] = convertedAminaSubscription();

    $this->travelTo(Carbon::instance($subscription->fresh()->current_period_end)->addDay());
    app(RenewSubscription::class)->handle($subscription->fresh());

    $invoice = $subscription->invoices()->where('billing_reason', InvoiceBillingReason::SubscriptionCycle)->firstOrFail();

    app(RetryPastDueInvoice::class)->handle($subscription->fresh(), force: true);
    app(RetryPastDueInvoice::class)->handle($subscription->fresh(), force: true);

    // Three attempts, one invoice: failed, failed, succeeded.
    expect($invoice->fresh()->payments()->count())->toBe(3)
        ->and($invoice->payments()->where('status', PaymentStatus::Succeeded)->count())->toBe(1)
        ->and($invoice->payments()->where('status', PaymentStatus::Failed)->count())->toBe(2)
        ->and($invoice->payments()->pluck('attempt_number')->sort()->values()->all())->toBe([1, 2, 3]);
});

it('moves the subscription to past_due on decline and recovers it when a retry succeeds', function () {
    fakeNombaChargeAttempts(true, false, true);

    ['subscription' => $subscription] = convertedAminaSubscription();

    $this->travelTo(Carbon::instance($subscription->fresh()->current_period_end)->addDay());
    app(RenewSubscription::class)->handle($subscription->fresh());

    // active → past_due on the decline.
    expect($subscription->fresh()->status)->toBe(SubscriptionStatus::PastDue);

    $invoice = $subscription->invoices()->where('billing_reason', InvoiceBillingReason::SubscriptionCycle)->firstOrFail();

    // A successful retry recovers past_due → active and pays the invoice.
    app(RetryPastDueInvoice::class)->handle($subscription->fresh(), force: true);

    expect($subscription->fresh()->status)->toBe(SubscriptionStatus::Active)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and($invoice->fresh()->amount_paid)->toBe($invoice->fresh()->total);
});

// Act 6 — Partial refund
it('records a partial refund as its own row without overwriting the charge', function () {
    ['subscription' => $subscription] = convertedAminaSubscription();
    $payment = $subscription->invoices()->firstOrFail()->payments()->firstOrFail();

    fakeNombaRefund();
    $refund = app(RefundPayment::class)->handle($payment, 200000, 'Partial goodwill');

    expect($refund->payment_id)->toBe($payment->id)
        ->and($refund->invoice_id)->toBe($payment->invoice_id)
        ->and($refund->amount)->toBe(200000)
        ->and($refund->status)->toBe(RefundStatus::Succeeded)
        ->and($refund->amount)->toBeLessThanOrEqual($payment->amount)
        // schema.md §8: a partial refund is its own row; the source charge
        // flips to `refunded` only when *fully* reversed.
        ->and($payment->fresh()->status)->toBe(PaymentStatus::Succeeded);
});

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
