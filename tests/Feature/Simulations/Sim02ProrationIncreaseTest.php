<?php

use App\Actions\Subscriptions\RenewSubscription;
use App\Actions\Subscriptions\UpdateSubscriptionItem;
use App\Enums\InvoiceBillingReason;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| SIM-02 — Mid-cycle upgrade with proration (quantity increase)
|--------------------------------------------------------------------------
|
| BILLING_SIMULATIONS.md SIM-02. A Team subscription on price_seat_m
| (₦1,000/seat/mo), 30-day cycle, quantity 10 → 15 on day 12 (18 days
| remaining). Proves the invoice ledger is the durable record — no temporal
| column on subscription_items. Promoted in V2-3. The seatSubscription()
| helper lives in tests/Pest.php (shared with SIM-03).
*/

it('writes a subscription_update invoice with paired proration lines for the covered window', function () {
    ['subscription' => $subscription, 'item' => $item] = seatSubscription(10);
    fakeNombaCharge();

    // Day 12 of 30 → 18 days remain.
    $this->travelTo(Carbon::instance($subscription->current_period_start)->addDays(12));
    app(UpdateSubscriptionItem::class)->handle($subscription->fresh(), $item->fresh(), quantity: 15);

    $invoice = $subscription->invoices()->where('billing_reason', InvoiceBillingReason::SubscriptionUpdate)->firstOrFail();
    $invoice->load('lines');

    $credit = $invoice->lines->firstWhere('unit_amount', -600000);
    $charge = $invoice->lines->firstWhere('unit_amount', 900000);

    // −(10 × 100000 × 18/30) and +(15 × 100000 × 18/30), both proration lines.
    expect($invoice->lines)->toHaveCount(2)
        ->and($credit)->not->toBeNull()
        ->and($credit->proration)->toBeTrue()
        ->and($charge)->not->toBeNull()
        ->and($charge->proration)->toBeTrue()
        ->and($charge->period_start->equalTo(Carbon::instance($subscription->current_period_start)->addDays(12)))->toBeTrue()
        ->and($charge->period_end->equalTo($subscription->current_period_end))->toBeTrue();
});

it('charges the net proration amount now and updates the item quantity', function () {
    ['subscription' => $subscription, 'item' => $item] = seatSubscription(10);
    fakeNombaCharge();

    $this->travelTo(Carbon::instance($subscription->current_period_start)->addDays(12));
    app(UpdateSubscriptionItem::class)->handle($subscription->fresh(), $item->fresh(), quantity: 15);

    $invoice = $subscription->invoices()->where('billing_reason', InvoiceBillingReason::SubscriptionUpdate)->firstOrFail();

    // Net +300000 (5 extra seats × 18/30); the item moves to 15 seats.
    expect($invoice->total)->toBe(300000)
        ->and($invoice->payments()->firstOrFail()->amount)->toBe(300000)
        ->and($item->fresh()->quantity)->toBe(15);
});

it('bills the next renewal at the full new quantity', function () {
    ['subscription' => $subscription, 'item' => $item] = seatSubscription(10);
    fakeNombaCharge();

    $this->travelTo(Carbon::instance($subscription->current_period_start)->addDays(12));
    app(UpdateSubscriptionItem::class)->handle($subscription->fresh(), $item->fresh(), quantity: 15);

    // Day 30 renewal bills 15 × 100000, no proration lines.
    $this->travelTo(Carbon::instance($subscription->fresh()->current_period_end)->addDay());
    app(RenewSubscription::class)->handle($subscription->fresh());

    $renewal = $subscription->invoices()->where('billing_reason', InvoiceBillingReason::SubscriptionCycle)->firstOrFail();
    $renewal->load('lines');

    expect($renewal->total)->toBe(1500000)
        ->and($renewal->lines->every(fn ($line) => ! $line->proration))->toBeTrue();
});

it('makes the seat history derivable from immutable invoice lines alone', function () {
    ['subscription' => $subscription, 'item' => $item] = seatSubscription(10);
    fakeNombaCharge();

    $this->travelTo(Carbon::instance($subscription->current_period_start)->addDays(12));
    app(UpdateSubscriptionItem::class)->handle($subscription->fresh(), $item->fresh(), quantity: 15);

    // Every proration term is an immutable line with a period window — the
    // "10 for 12 days, 15 for 18 days" history is reconstructable, not stored.
    $lines = $subscription->invoices()
        ->where('billing_reason', InvoiceBillingReason::SubscriptionUpdate)
        ->firstOrFail()->lines;

    expect($lines->every(fn ($line) => $line->period_start !== null && $line->period_end !== null))->toBeTrue()
        ->and((int) $lines->sum('total'))->toBe(300000);
});
