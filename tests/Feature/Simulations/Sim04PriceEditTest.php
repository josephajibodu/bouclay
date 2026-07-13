<?php

use App\Actions\Catalog\ReplacePrice;
use App\Enums\BillingInterval;
use App\Enums\CatalogStatus;
use App\Enums\PlanStatus;
use App\Enums\PriceType;
use App\Enums\SubscriptionItemKind;
use App\Enums\SubscriptionStatus;
use App\Exceptions\ImmutablePriceViolation;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Subscription;
use App\Models\SubscriptionItem;
use App\Models\Team;

/*
|--------------------------------------------------------------------------
| SIM-04 — Merchant edits a live price (immutability proven)
|--------------------------------------------------------------------------
|
| BILLING_SIMULATIONS.md SIM-04. Premium Plus exists at ₦8,000
| (price_prem_plus_m) with Amina subscribed; the merchant raises it to
| ₦9,000. Editing a referenced price must create a successor row, never
| mutate. Promoted in V2-1 (ReplacePrice + saving guard).
*/

/**
 * Build the SIM-04 starting state: a Premium Plus plan + ₦8,000 price that
 * Amina already subscribes to (so the price is referenced and frozen).
 *
 * @return array{price: Price, subscriptionItem: SubscriptionItem, amina: Customer, team: Team}
 */
function premiumPlusSubscribed(): array
{
    $fx = naijaStreamFixture();
    $team = $fx['team'];
    $amina = $fx['amina'];

    $plan = Plan::factory()->for($team)->for($fx['naijastream'])->create([
        'name' => 'Premium Plus',
        'status' => PlanStatus::Active,
    ]);

    $price = Price::factory()->for($team)->for($fx['naijastream'])->for($plan)->create([
        'name' => 'Premium Plus Monthly',
        'type' => PriceType::Recurring,
        'unit_amount' => 800000,
        'currency' => 'NGN',
        'billing_interval' => BillingInterval::Month,
        'version' => 1,
        'purchasable' => true,
    ]);

    $subscription = Subscription::factory()->for($team)->for($amina)->create([
        'status' => SubscriptionStatus::Active,
        'currency' => 'NGN',
    ]);

    $item = SubscriptionItem::factory()->for($subscription)->create([
        'price_id' => $price->id,
        'plan_id' => $plan->id,
        'product_id' => $fx['naijastream']->id,
        'kind' => SubscriptionItemKind::Plan,
        'quantity' => 1,
    ]);

    return ['price' => $price, 'subscriptionItem' => $item, 'amina' => $amina, 'team' => $team];
}

it('creates a successor row on edit instead of mutating the referenced price', function () {
    ['price' => $original] = premiumPlusSubscribed();

    $successor = app(ReplacePrice::class)->handle($original, ['unit_amount' => 9000]);

    // New row carries the new amount, points back at the original, v2.
    expect($successor->id)->not->toBe($original->id)
        ->and($successor->unit_amount)->toBe(900000)
        ->and($successor->replaces_price_id)->toBe($original->id)
        ->and($successor->version)->toBe(2)
        ->and($successor->status)->toBe(CatalogStatus::Active);

    // The original's financial fields are byte-identical to before the edit.
    $original->refresh();
    expect($original->unit_amount)->toBe(800000)
        ->and($original->version)->toBe(1);
});

it('archives the superseded price', function () {
    ['price' => $original] = premiumPlusSubscribed();

    app(ReplacePrice::class)->handle($original, ['unit_amount' => 9000]);

    expect($original->refresh()->status)->toBe(CatalogStatus::Archived);
});

it('keeps existing subscribers grandfathered on the original price', function () {
    ['price' => $original, 'subscriptionItem' => $item] = premiumPlusSubscribed();

    app(ReplacePrice::class)->handle($original, ['unit_amount' => 9000]);

    // Amina's item still references the ₦8,000 row.
    expect($item->refresh()->price_id)->toBe($original->id)
        ->and($item->price->unit_amount)->toBe(800000);
});

it('offers only the successor price to new signups', function () {
    ['price' => $original] = premiumPlusSubscribed();

    $successor = app(ReplacePrice::class)->handle($original, ['unit_amount' => 9000]);

    $purchasable = Price::query()->purchasableForNewSubscriptions()->pluck('id');

    expect($purchasable)->toContain($successor->id)
        ->and($purchasable)->not->toContain($original->id);
});

it('keeps the price lineage walkable through replaces_price_id', function () {
    ['price' => $v1] = premiumPlusSubscribed();

    $v2 = app(ReplacePrice::class)->handle($v1, ['unit_amount' => 9000]);

    // Reference v2 so a second edit must also replace, chaining v3 → v2 → v1.
    SubscriptionItem::factory()->for(
        Subscription::factory()->for($v2->team)->create(['currency' => 'NGN'])
    )->create([
        'price_id' => $v2->id,
        'plan_id' => $v2->plan_id,
        'product_id' => $v2->product_id,
    ]);

    $v3 = app(ReplacePrice::class)->handle($v2, ['unit_amount' => 10000]);

    expect($v3->replaces_price_id)->toBe($v2->id)
        ->and($v3->replacesPrice->replaces_price_id)->toBe($v1->id)
        ->and($v3->version)->toBe(3);
});

it('blocks direct mutation of frozen columns on a referenced price', function () {
    ['price' => $original] = premiumPlusSubscribed();

    expect(fn () => $original->update(['unit_amount' => 900000]))
        ->toThrow(ImmutablePriceViolation::class);

    // The row is untouched after the rejected write.
    expect($original->refresh()->unit_amount)->toBe(800000);
});

it('still allows archiving and renaming a referenced price in place', function () {
    ['price' => $original] = premiumPlusSubscribed();

    // status, name, custom_data are never frozen.
    $original->update(['name' => 'Premium Plus (legacy)', 'status' => CatalogStatus::Archived]);

    expect($original->refresh()->name)->toBe('Premium Plus (legacy)')
        ->and($original->status)->toBe(CatalogStatus::Archived);
});
