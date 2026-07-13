<?php

use App\Actions\Subscriptions\ApplyScheduledChange;
use App\Actions\Subscriptions\RenewSubscription;
use App\Actions\Subscriptions\UpdateSubscriptionItem;
use App\Enums\InvoiceBillingReason;
use App\Enums\ProrationBehavior;
use App\Models\ScheduledChange;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| ADV-04 — Quantity increase AND decrease with proration
|--------------------------------------------------------------------------
|
| BILLING_SIMULATIONS.md ADV-04: the two mid-cycle directions follow
| different locked policies (GAP-6) — increases prorate now (SIM-02),
| decreases defer to period end (SIM-03). Promoted in V2-3. Uses
| seatSubscription() from tests/Pest.php.
*/

it('prorates and charges an increase immediately by default', function () {
    ['subscription' => $subscription, 'item' => $item] = seatSubscription(10);
    fakeNombaCharge();

    $this->travelTo(Carbon::instance($subscription->current_period_start)->addDays(12));
    app(UpdateSubscriptionItem::class)->handle($subscription->fresh(), $item->fresh(), quantity: 15);

    // Default for an increase is `always`: proration invoice, net charged now.
    $invoice = $subscription->invoices()->where('billing_reason', InvoiceBillingReason::SubscriptionUpdate)->firstOrFail();

    expect($invoice->total)->toBe(300000)
        ->and($item->fresh()->quantity)->toBe(15)
        ->and(ScheduledChange::query()->count())->toBe(0);
});

it('defers a decrease to next cycle by default', function () {
    ['subscription' => $subscription, 'item' => $item] = seatSubscription(15);

    $this->travelTo(Carbon::instance($subscription->current_period_start)->addDays(20));
    app(UpdateSubscriptionItem::class)->handle($subscription->fresh(), $item->fresh(), quantity: 10);

    // Default for a decrease is `next_cycle`: scheduled row, no money now.
    expect(ScheduledChange::query()->count())->toBe(1)
        ->and($subscription->invoices()->count())->toBe(0)
        ->and($item->fresh()->quantity)->toBe(15);
});

it('honors an explicit proration_behavior of none by applying now and billing nothing', function () {
    ['subscription' => $subscription, 'item' => $item] = seatSubscription(10);
    fakeNombaCharge();

    $this->travelTo(Carbon::instance($subscription->current_period_start)->addDays(12));
    app(UpdateSubscriptionItem::class)->handle(
        $subscription->fresh(),
        $item->fresh(),
        quantity: 15,
        prorationBehavior: ProrationBehavior::None,
    );

    // Applied immediately, but no proration invoice — a goodwill edit.
    expect($item->fresh()->quantity)->toBe(15)
        ->and($subscription->invoices()->count())->toBe(0)
        ->and(ScheduledChange::query()->count())->toBe(0);

    // The next cycle simply bills the new quantity.
    $this->travelTo(Carbon::instance($subscription->current_period_end)->addDay());
    app(RenewSubscription::class)->handle($subscription->fresh());

    expect($subscription->invoices()->where('billing_reason', InvoiceBillingReason::SubscriptionCycle)->firstOrFail()->total)
        ->toBe(1500000);
});

it('nets an increase-then-decrease sequence per policy rather than mixing them', function () {
    ['subscription' => $subscription, 'item' => $item] = seatSubscription(10);
    fakeNombaCharge();

    // Day 12: increase 10 → 15 prorates + charges now.
    $this->travelTo(Carbon::instance($subscription->current_period_start)->addDays(12));
    app(UpdateSubscriptionItem::class)->handle($subscription->fresh(), $item->fresh(), quantity: 15);

    // Day 20: decrease 15 → 12 defers to the boundary.
    $this->travelTo(Carbon::instance($subscription->current_period_start)->addDays(20));
    app(UpdateSubscriptionItem::class)->handle($subscription->fresh(), $item->fresh(), quantity: 12);

    expect($item->fresh()->quantity)->toBe(15)
        ->and(ScheduledChange::query()->count())->toBe(1);

    // Day 30: apply the scheduled decrease, then the renewal bills 12 seats
    // flat — no proration line rides along.
    $this->travelTo(Carbon::instance($subscription->current_period_end)->addDay());
    app(ApplyScheduledChange::class)->handle(ScheduledChange::query()->firstOrFail());
    app(RenewSubscription::class)->handle($subscription->fresh());

    $renewal = $subscription->invoices()->where('billing_reason', InvoiceBillingReason::SubscriptionCycle)->firstOrFail();
    $renewal->load('lines');

    expect($item->fresh()->quantity)->toBe(12)
        ->and($renewal->total)->toBe(1200000)
        ->and($renewal->lines->every(fn ($line) => ! $line->proration))->toBeTrue();
});
