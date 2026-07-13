<?php

use App\Actions\Subscriptions\ApplyScheduledChange;
use App\Actions\Subscriptions\UpdateSubscriptionItem;
use App\Enums\ScheduledChangeAction;
use App\Enums\SubscriptionItemKind;
use App\Models\ScheduledChange;
use App\Models\SubscriptionItem;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

/*
|--------------------------------------------------------------------------
| ADV-02 — Downgrade / quantity change scheduled for next renewal
|--------------------------------------------------------------------------
|
| BILLING_SIMULATIONS.md ADV-02 / GAP-2 (resolved): a downgrade effective
| at next renewal lives in scheduled_changes{action=update} with an item
| payload. Promoted in V2-3. Uses seatSubscription() from tests/Pest.php.
*/

it('writes an update row with the target item state in its payload', function () {
    ['subscription' => $subscription, 'item' => $item] = seatSubscription(15);

    app(UpdateSubscriptionItem::class)->handle($subscription->fresh(), $item->fresh(), quantity: 10);

    $change = ScheduledChange::query()->firstOrFail();

    expect($change->action)->toBe(ScheduledChangeAction::Update)
        ->and($change->payload)->toBe(['subscription_item_id' => $item->id, 'quantity' => 10])
        ->and($change->effective_at->equalTo($subscription->current_period_end))->toBeTrue();
});

it('writes one row per item change sharing effective_at for multi-item downgrades', function () {
    ['fx' => $fx, 'subscription' => $subscription, 'item' => $seat] = seatSubscription(15);

    // A second item (add-on) to downgrade alongside the seats.
    $addon = SubscriptionItem::factory()->for($subscription)->create([
        'price_id' => $fx['price_sports_m']->id,
        'plan_id' => $fx['sportsPackPlan']->id,
        'product_id' => $fx['sportsPack']->id,
        'kind' => SubscriptionItemKind::Addon,
        'quantity' => 1,
    ]);

    app(UpdateSubscriptionItem::class)->handle($subscription->fresh(), $seat->fresh(), quantity: 10);
    app(UpdateSubscriptionItem::class)->handle($subscription->fresh(), $addon->fresh(), remove: true);

    $changes = ScheduledChange::query()->get();

    // Two rows, one per item, both landing at the same boundary.
    expect($changes)->toHaveCount(2)
        ->and($changes->pluck('effective_at')->map->timestamp->unique())->toHaveCount(1)
        ->and($changes->firstWhere('payload.subscription_item_id', $addon->id)->payload['remove'])->toBeTrue();
});

it('applies the payload at the boundary and marks the row applied', function () {
    ['subscription' => $subscription, 'item' => $item] = seatSubscription(15);

    app(UpdateSubscriptionItem::class)->handle($subscription->fresh(), $item->fresh(), quantity: 10);

    $this->travelTo(Carbon::instance($subscription->current_period_end)->addDay());
    app(ApplyScheduledChange::class)->handle(ScheduledChange::query()->firstOrFail());

    expect($item->fresh()->quantity)->toBe(10)
        ->and(ScheduledChange::query()->firstOrFail()->applied_at)->not->toBeNull();
});

it('surfaces pending downgrades on the subscription hub until applied', function () {
    ['fx' => $fx, 'subscription' => $subscription, 'item' => $item] = seatSubscription(15);

    app(UpdateSubscriptionItem::class)->handle($subscription->fresh(), $item->fresh(), quantity: 10);

    $this->actingAs($fx['owner'])
        ->get(route('subscriptions.show', $subscription))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('subscriptions/show')
            ->has('scheduledChanges', 1)
            ->where('scheduledChanges.0.action', 'update')
            ->where('scheduledChanges.0.description', 'NaijaStream: 15 → 10 at next renewal'));
});
