<?php

use App\Actions\Subscriptions\AdvanceSubscriptionPhases;
use App\Actions\Subscriptions\CreateSubscription;
use App\Actions\Subscriptions\UpdateSubscriptionItem;
use App\Enums\SubscriptionStatus;
use App\Models\PaymentMethod;
use App\Models\ScheduledChange;
use App\Models\Subscription;
use App\Models\SubscriptionItem;
use App\Models\TeamProcessorConnection;

/*
|--------------------------------------------------------------------------
| ADV-01 — Upgrade during a free trial (no money moved yet)
|--------------------------------------------------------------------------
|
| BILLING_SIMULATIONS.md ADV-01 / GAP-6 (locked): changes while trialing
| apply immediately with NO proration — no money has moved; the conversion
| invoice reflects the final composition. Promoted in V2-3.
*/

/**
 * A trialing Premium subscription (7-day trial) with a card on file, and its
 * single plan item.
 *
 * @return array{fx: array<string, mixed>, subscription: Subscription, item: SubscriptionItem}
 */
function trialingPremiumSubscription(): array
{
    $fx = naijaStreamFixture();
    $team = $fx['team'];

    $card = PaymentMethod::factory()->for($team)->for($fx['amina'])->create(['is_default' => true]);

    $subscription = app(CreateSubscription::class)->handle($team, [
        'customer_id' => $fx['amina']->id,
        'collection_mode' => 'automatic',
        'payment_method_id' => $card->id,
        'items' => [['price_id' => $fx['price_prem_m']->id, 'quantity' => 1]],
    ]);

    return ['fx' => $fx, 'subscription' => $subscription, 'item' => $subscription->items()->firstOrFail()];
}

it('applies an item change immediately while trialing', function () {
    ['subscription' => $subscription, 'item' => $item] = trialingPremiumSubscription();

    expect($subscription->status)->toBe(SubscriptionStatus::Trialing);

    // Upgrade to 2 seats mid-trial — applied in place, no scheduled row.
    app(UpdateSubscriptionItem::class)->handle($subscription->fresh(), $item->fresh(), quantity: 2);

    expect($item->fresh()->quantity)->toBe(2)
        ->and(ScheduledChange::query()->count())->toBe(0);
});

it('creates no proration invoice for a change made during the trial', function () {
    ['subscription' => $subscription, 'item' => $item] = trialingPremiumSubscription();

    app(UpdateSubscriptionItem::class)->handle($subscription->fresh(), $item->fresh(), quantity: 2);

    // No money moves during the trial — zero invoices before conversion.
    expect($subscription->invoices()->count())->toBe(0);
});

it('bills the conversion invoice on the final post-upgrade composition', function () {
    ['fx' => $fx, 'subscription' => $subscription, 'item' => $item] = trialingPremiumSubscription();
    TeamProcessorConnection::factory()->for($fx['team'])->testConnected()->create();
    fakeNombaCharge();

    app(UpdateSubscriptionItem::class)->handle($subscription->fresh(), $item->fresh(), quantity: 2);

    // At trial end the conversion invoice reflects 2 seats, not the 1 chosen
    // at signup: 2 × 500000 = 1000000.
    $this->travelTo(now()->addDays(8));
    app(AdvanceSubscriptionPhases::class)->handle($subscription->fresh());

    $invoice = $subscription->invoices()->firstOrFail();

    expect($subscription->fresh()->status)->toBe(SubscriptionStatus::Active)
        ->and($invoice->total)->toBe(1000000);
});
