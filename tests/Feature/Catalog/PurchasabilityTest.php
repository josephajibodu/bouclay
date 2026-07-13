<?php

use App\Enums\BillingInterval;
use App\Enums\CatalogStatus;
use App\Enums\PlanStatus;
use App\Enums\PriceType;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Team;

/**
 * The purchasableForNewSubscriptions scope is the single gate every picker,
 * payment link, and subscribe validator reads (IMPLEMENTATION_V2 §V2-1). It
 * must exclude, in one place, every not-for-sale case.
 */
function makePrice(Team $team, Plan $plan, array $overrides = []): Price
{
    return Price::factory()->for($team)->for($plan->product)->for($plan)->create([
        'type' => PriceType::Recurring,
        'unit_amount' => 500000,
        'currency' => 'NGN',
        'billing_interval' => BillingInterval::Month,
        'status' => CatalogStatus::Active,
        'purchasable' => true,
        ...$overrides,
    ]);
}

test('the scope offers an active plan-bearing recurring price', function () {
    $team = Team::factory()->create();
    $plan = Plan::factory()->for($team)->for(Product::factory()->for($team))->create(['status' => PlanStatus::Active]);
    $price = makePrice($team, $plan);

    expect(Price::query()->purchasableForNewSubscriptions()->pluck('id'))
        ->toContain($price->id);
    expect($price->isPurchasableForNewSubscriptions())->toBeTrue();
});

test('the scope hides prices under a draft or archived plan', function () {
    $team = Team::factory()->create();
    $product = Product::factory()->for($team)->create();
    $draftPlan = Plan::factory()->for($team)->for($product)->create(['status' => PlanStatus::Draft]);
    $archivedPlan = Plan::factory()->for($team)->for($product)->create(['status' => PlanStatus::Archived]);

    $draftPrice = makePrice($team, $draftPlan);
    $archivedPlanPrice = makePrice($team, $archivedPlan);

    $ids = Price::query()->purchasableForNewSubscriptions()->pluck('id');

    expect($ids)->not->toContain($draftPrice->id)
        ->and($ids)->not->toContain($archivedPlanPrice->id);
    expect($draftPrice->isPurchasableForNewSubscriptions())->toBeFalse();
});

test('the scope hides archived, phase-only, and one-time prices', function () {
    $team = Team::factory()->create();
    $plan = Plan::factory()->for($team)->for(Product::factory()->for($team))->create(['status' => PlanStatus::Active]);

    $archived = makePrice($team, $plan, ['status' => CatalogStatus::Archived]);
    $phaseOnly = makePrice($team, $plan, ['purchasable' => false]);
    $oneTime = makePrice($team, $plan, ['type' => PriceType::OneTime, 'billing_interval' => null]);

    $ids = Price::query()->purchasableForNewSubscriptions()->pluck('id');

    expect($ids)->not->toContain($archived->id)
        ->and($ids)->not->toContain($phaseOnly->id)
        ->and($ids)->not->toContain($oneTime->id);
});

test('the scope hides a plan-less price', function () {
    $team = Team::factory()->create();
    $product = Product::factory()->for($team)->create();
    $planless = Price::factory()->for($team)->for($product)->create([
        'plan_id' => null,
        'type' => PriceType::Recurring,
        'unit_amount' => 500000,
        'currency' => 'NGN',
        'billing_interval' => BillingInterval::Month,
        'purchasable' => true,
    ]);

    expect(Price::query()->purchasableForNewSubscriptions()->pluck('id'))
        ->not->toContain($planless->id);
});
