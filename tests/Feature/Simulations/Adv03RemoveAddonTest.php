<?php

use App\Actions\Subscriptions\ApplyScheduledChange;
use App\Actions\Subscriptions\RenewSubscription;
use App\Actions\Subscriptions\UpdateSubscriptionItem;
use App\Enums\InvoiceBillingReason;
use App\Enums\ScheduledChangeAction;
use App\Enums\SubscriptionItemKind;
use App\Enums\SubscriptionItemStatus;
use App\Models\ScheduledChange;
use App\Models\Subscription;
use App\Models\SubscriptionItem;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| ADV-03 — Remove an add-on mid-cycle
|--------------------------------------------------------------------------
|
| BILLING_SIMULATIONS.md ADV-03 / GAP-3 (resolved by policy): removal takes
| effect at next renewal; no mid-cycle credit is ever created in MVP (there
| is no customer balance ledger to hold one). Promoted in V2-3.
|
| Builds on seatSubscription() (tests/Pest.php) plus a Sports Pack add-on.
*/

/**
 * A seat subscription that also carries a Sports Pack add-on item.
 *
 * @return array{fx: array<string, mixed>, subscription: Subscription, seat: SubscriptionItem, addon: SubscriptionItem}
 */
function seatSubscriptionWithAddon(int $quantity): array
{
    ['fx' => $fx, 'subscription' => $subscription, 'item' => $seat] = seatSubscription($quantity);

    $addon = SubscriptionItem::factory()->for($subscription)->create([
        'price_id' => $fx['price_sports_m']->id,
        'plan_id' => $fx['sportsPackPlan']->id,
        'product_id' => $fx['sportsPack']->id,
        'kind' => SubscriptionItemKind::Addon,
        'quantity' => 1,
    ]);

    return ['fx' => $fx, 'subscription' => $subscription, 'seat' => $seat, 'addon' => $addon];
}

it('schedules add-on removal for the next renewal instead of removing it now', function () {
    ['subscription' => $subscription, 'addon' => $addon] = seatSubscriptionWithAddon(10);

    app(UpdateSubscriptionItem::class)->handle($subscription->fresh(), $addon->fresh(), remove: true);

    $change = ScheduledChange::query()->firstOrFail();

    expect($change->action)->toBe(ScheduledChangeAction::Update)
        ->and($change->payload)->toBe(['subscription_item_id' => $addon->id, 'remove' => true])
        ->and($change->effective_at->equalTo($subscription->current_period_end))->toBeTrue()
        // Still active and billing until the boundary.
        ->and($addon->fresh()->status)->toBe(SubscriptionItemStatus::Active);
});

it('creates no mid-cycle credit for the removed add-on', function () {
    ['subscription' => $subscription, 'addon' => $addon] = seatSubscriptionWithAddon(10);

    app(UpdateSubscriptionItem::class)->handle($subscription->fresh(), $addon->fresh(), remove: true);

    // No invoice, no negative line, no refund — the paid cycle is untouched.
    expect($subscription->invoices()->count())->toBe(0);
});

it('marks the item removed at the boundary and stops billing it', function () {
    ['subscription' => $subscription, 'seat' => $seat, 'addon' => $addon] = seatSubscriptionWithAddon(10);
    fakeNombaCharge();

    app(UpdateSubscriptionItem::class)->handle($subscription->fresh(), $addon->fresh(), remove: true);

    // At the boundary the add-on is removed, then the renewal bills only the
    // seats (10 × 100000), with no Sports Pack line.
    $this->travelTo(Carbon::instance($subscription->current_period_end)->addDay());
    app(ApplyScheduledChange::class)->handle(ScheduledChange::query()->firstOrFail());
    app(RenewSubscription::class)->handle($subscription->fresh());

    $renewal = $subscription->invoices()->where('billing_reason', InvoiceBillingReason::SubscriptionCycle)->firstOrFail();
    $renewal->load('lines');

    expect($addon->fresh()->status)->toBe(SubscriptionItemStatus::Removed)
        ->and($renewal->total)->toBe(1000000)
        ->and($renewal->lines->firstWhere('subscription_item_id', $addon->id))->toBeNull();
});
