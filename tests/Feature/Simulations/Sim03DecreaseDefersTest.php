<?php

use App\Actions\Subscriptions\ApplyScheduledChange;
use App\Actions\Subscriptions\RenewSubscription;
use App\Actions\Subscriptions\UpdateSubscriptionItem;
use App\Enums\InvoiceBillingReason;
use App\Enums\ScheduledChangeAction;
use App\Models\ScheduledChange;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| SIM-03 — Mid-cycle decrease defers to next renewal
|--------------------------------------------------------------------------
|
| BILLING_SIMULATIONS.md SIM-03 (verdict as updated by GAP-2/3): a decrease
| on an already-paid period would yield a net credit with nowhere to land
| (no customer balance ledger in MVP), so decreases take effect at the next
| renewal via a scheduled_changes update row. Team subscription on
| price_seat_m, quantity 15 → 10 on day 20. Promoted in V2-3.
|
| Reuses seatSubscription() from Sim02ProrationIncreaseTest.
*/

it('writes a scheduled update row instead of prorating a decrease', function () {
    ['subscription' => $subscription, 'item' => $item] = seatSubscription(15);

    $this->travelTo(Carbon::instance($subscription->current_period_start)->addDays(20));
    app(UpdateSubscriptionItem::class)->handle($subscription->fresh(), $item->fresh(), quantity: 10);

    $change = ScheduledChange::query()->firstOrFail();

    expect($change->action)->toBe(ScheduledChangeAction::Update)
        ->and($change->payload['subscription_item_id'])->toBe($item->id)
        ->and($change->payload['quantity'])->toBe(10)
        ->and($change->applied_at)->toBeNull()
        ->and($change->effective_at->equalTo($subscription->current_period_end))->toBeTrue();
});

it('creates no mid-cycle credit and no proration lines for the decrease', function () {
    ['subscription' => $subscription, 'item' => $item] = seatSubscription(15);

    $this->travelTo(Carbon::instance($subscription->current_period_start)->addDays(20));
    app(UpdateSubscriptionItem::class)->handle($subscription->fresh(), $item->fresh(), quantity: 10);

    // No invoice or line is written at day 20 — the already-paid cycle is
    // untouched.
    expect($subscription->invoices()->count())->toBe(0);
});

it('keeps the item at the old quantity until the boundary', function () {
    ['subscription' => $subscription, 'item' => $item] = seatSubscription(15);

    $this->travelTo(Carbon::instance($subscription->current_period_start)->addDays(20));
    app(UpdateSubscriptionItem::class)->handle($subscription->fresh(), $item->fresh(), quantity: 10);

    expect($item->fresh()->quantity)->toBe(15);
});

it('bills the day-30 renewal at the reduced quantity with no proration lines', function () {
    ['subscription' => $subscription, 'item' => $item] = seatSubscription(15);
    fakeNombaCharge();

    $this->travelTo(Carbon::instance($subscription->current_period_start)->addDays(20));
    app(UpdateSubscriptionItem::class)->handle($subscription->fresh(), $item->fresh(), quantity: 10);

    // At the boundary: apply-scheduled-changes stamps the row applied and
    // swaps the item, then the renewal bills 10 × 100000.
    $this->travelTo(Carbon::instance($subscription->current_period_end)->addDay());

    app(ApplyScheduledChange::class)->handle(ScheduledChange::query()->firstOrFail());
    app(RenewSubscription::class)->handle($subscription->fresh());

    $renewal = $subscription->invoices()->where('billing_reason', InvoiceBillingReason::SubscriptionCycle)->firstOrFail();
    $renewal->load('lines');

    expect($item->fresh()->quantity)->toBe(10)
        ->and(ScheduledChange::query()->firstOrFail()->applied_at)->not->toBeNull()
        ->and($renewal->total)->toBe(1000000)
        ->and($renewal->lines->every(fn ($line) => ! $line->proration))->toBeTrue();
});

it('lets the merchant delete the pending decrease before it applies', function () {
    ['fx' => $fx, 'subscription' => $subscription, 'item' => $item] = seatSubscription(15);

    $this->travelTo(Carbon::instance($subscription->current_period_start)->addDays(20));
    app(UpdateSubscriptionItem::class)->handle($subscription->fresh(), $item->fresh(), quantity: 10);

    $change = ScheduledChange::query()->firstOrFail();

    // Cancelling a scheduled downgrade is a row delete via the hub route.
    $this->actingAs($fx['owner'])
        ->delete(route('subscriptions.scheduled-changes.destroy', [$subscription, $change]))
        ->assertRedirect();

    expect(ScheduledChange::query()->count())->toBe(0)
        ->and($item->fresh()->quantity)->toBe(15);
});
