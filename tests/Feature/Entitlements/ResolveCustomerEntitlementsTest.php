<?php

use App\Actions\Entitlements\ResolveCustomerEntitlements;
use App\Enums\InvoiceBillingReason;
use App\Enums\InvoiceStatus;
use App\Enums\SubscriptionItemKind;
use App\Enums\SubscriptionItemStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Invoice;
use App\Models\Subscription;

/*
|--------------------------------------------------------------------------
| Entitlement resolution (IMPLEMENTATION_V2 §V2-5)
|--------------------------------------------------------------------------
|
| The union of grants across the plans/products on a customer's
| access-granting subscriptions, honouring `ends_at`. SIM-01 covers the
| headline path; these pin the edges it doesn't reach — the statuses, the
| grace window, and the fact that access never consults billing state.
*/

/**
 * Amina subscribed to Premium (grants hd_streaming via plan) with a Sports
 * Pack add-on (grants sports_channels via product).
 *
 * @param  array<string, mixed>  $attributes
 * @return array{fx: array<string, mixed>, subscription: Subscription}
 */
function entitledSubscription(array $attributes = []): array
{
    $fx = naijaStreamFixture();

    $subscription = Subscription::factory()->for($fx['team'])->for($fx['amina'])->create([
        'status' => SubscriptionStatus::Active,
        'currency' => 'NGN',
        'current_period_start' => now(),
        'current_period_end' => now()->addMonth(),
        ...$attributes,
    ]);

    $subscription->items()->create([
        'price_id' => $fx['price_prem_m']->id,
        'plan_id' => $fx['premium']->id,
        'product_id' => $fx['naijastream']->id,
        'kind' => SubscriptionItemKind::Plan,
        'quantity' => 1,
        'status' => SubscriptionItemStatus::Active,
    ]);

    $subscription->items()->create([
        'price_id' => $fx['price_sports_m']->id,
        'plan_id' => $fx['sportsPackPlan']->id,
        'product_id' => $fx['sportsPack']->id,
        'kind' => SubscriptionItemKind::Addon,
        'quantity' => 1,
        'status' => SubscriptionItemStatus::Active,
    ]);

    return ['fx' => $fx, 'subscription' => $subscription->fresh(['items'])];
}

it('resolves the union of plan and product grants', function () {
    ['fx' => $fx] = entitledSubscription();

    expect($fx['amina']->entitlementCodes())->toBe(['hd_streaming', 'sports_channels']);
});

it('grants access in every status that should have it', function (SubscriptionStatus $status) {
    ['fx' => $fx] = entitledSubscription(['status' => $status]);

    expect($fx['amina']->entitlementCodes())->toBe(['hd_streaming', 'sports_channels']);
})->with([
    'trialing' => SubscriptionStatus::Trialing,
    'active' => SubscriptionStatus::Active,
    // past_due keeps access on purpose: locking a customer out mid-dunning
    // turns a recoverable card decline into a cancellation.
    'past_due' => SubscriptionStatus::PastDue,
]);

it('grants nothing in a status that should not have it', function (SubscriptionStatus $status) {
    ['fx' => $fx] = entitledSubscription(['status' => $status]);

    expect($fx['amina']->entitlementCodes())->toBe([]);
})->with([
    // Never paid — access hasn't started.
    'incomplete' => SubscriptionStatus::Incomplete,
    'incomplete_expired' => SubscriptionStatus::IncompleteExpired,
    // Revoking access is the entire point of pausing.
    'paused' => SubscriptionStatus::Paused,
    'canceled' => SubscriptionStatus::Canceled,
]);

it('revokes access once ends_at has passed, whatever the status says', function () {
    ['fx' => $fx] = entitledSubscription([
        'status' => SubscriptionStatus::Active,
        'ends_at' => now()->subDay(),
    ]);

    // A stale `active` row with a past ends_at must not keep serving access —
    // status alone is not the answer.
    expect($fx['amina']->entitlementCodes())->toBe([]);
});

it('keeps access while ends_at is still in the future', function () {
    ['fx' => $fx] = entitledSubscription([
        'status' => SubscriptionStatus::PastDue,
        'ends_at' => now()->addDays(3),
    ]);

    // The dunning grace window: still past_due, not yet expired.
    expect($fx['amina']->entitlementCodes())->toBe(['hd_streaming', 'sports_channels']);
});

it('ignores a removed item', function () {
    ['fx' => $fx, 'subscription' => $subscription] = entitledSubscription();

    $subscription->items()
        ->where('kind', SubscriptionItemKind::Addon)
        ->update(['status' => SubscriptionItemStatus::Removed]);

    // A removed add-on is history, not access.
    expect($fx['amina']->entitlementCodes())->toBe(['hd_streaming']);
});

it('dedupes an entitlement granted by two subscriptions', function () {
    ['fx' => $fx] = entitledSubscription();

    // A second Premium subscription grants hd_streaming again — the answer is
    // a set, not a tally.
    $second = Subscription::factory()->for($fx['team'])->for($fx['amina'])->create([
        'status' => SubscriptionStatus::Active,
        'currency' => 'NGN',
        'current_period_start' => now(),
        'current_period_end' => now()->addMonth(),
    ]);

    $second->items()->create([
        'price_id' => $fx['price_prem_m']->id,
        'plan_id' => $fx['premium']->id,
        'product_id' => $fx['naijastream']->id,
        'kind' => SubscriptionItemKind::Plan,
        'quantity' => 1,
        'status' => SubscriptionItemStatus::Active,
    ]);

    expect($fx['amina']->entitlementCodes())->toBe(['hd_streaming', 'sports_channels']);
});

it('never consults billing state', function () {
    ['fx' => $fx, 'subscription' => $subscription] = entitledSubscription();

    // An unpaid invoice does NOT revoke access on its own (schema.md §4) —
    // dunning revokes by moving the subscription's status, which is a decision
    // the billing engine makes explicitly.
    Invoice::factory()
        ->for($fx['team'])
        ->for($fx['amina'])
        ->for($subscription)
        ->create([
            'status' => InvoiceStatus::Open,
            'billing_reason' => InvoiceBillingReason::SubscriptionCycle,
            'amount_paid' => 0,
        ]);

    expect($fx['amina']->entitlementCodes())->toBe(['hd_streaming', 'sports_channels']);
});

it('resolves nothing for a customer with no subscriptions', function () {
    $fx = naijaStreamFixture();

    expect($fx['amina']->entitlementCodes())->toBe([])
        ->and($fx['amina']->hasEntitlement('hd_streaming'))->toBeFalse();
});

it('never leaks another team’s entitlements', function () {
    ['fx' => $fx] = entitledSubscription();

    // Another team defines an entitlement with the SAME code, granted by its
    // own plan. Codes are only unique per team, so a resolver that forgot to
    // scope would happily return it.
    $other = naijaStreamFixture();
    $other['hdStreaming']->grants()->create([
        'team_id' => $other['team']->id,
        'grantor_type' => 'plan',
        'grantor_id' => $fx['premium']->id,
    ]);

    expect($fx['amina']->entitlements()->get('hd_streaming')->team_id)->toBe($fx['team']->id);
    expect($other['amina']->entitlementCodes())->toBe([]);
});

it('exposes the entitlements one subscription grants, for event payloads', function () {
    ['subscription' => $subscription] = entitledSubscription();

    $codes = app(ResolveCustomerEntitlements::class)
        ->codesForSubscription($subscription);

    expect($codes)->toBe(['hd_streaming', 'sports_channels']);
});
