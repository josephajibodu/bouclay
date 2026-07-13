<?php

use App\Enums\BillingInterval;
use App\Enums\PriceType;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\SubscriptionItem;
use App\Models\Team;
use App\Models\User;

function phaseFixture(): array
{
    $owner = User::factory()->create();
    $team = Team::factory()->create(['default_currency' => 'NGN']);
    $product = Product::factory()->for($team)->create();
    $plan = Plan::factory()->for($team)->for($product)->create();
    $price = Price::factory()->for($team)->for($product)->for($plan)->create([
        'type' => PriceType::Recurring,
        'unit_amount' => 500000,
        'currency' => 'NGN',
        'billing_interval' => BillingInterval::Month,
    ]);

    attachTeamOwner($team, $owner);
    $owner->switchTeam($team);

    return compact('owner', 'team', 'product', 'plan', 'price');
}

test('authoring an inline phase auto-creates a hidden charge price', function () {
    ['owner' => $owner, 'product' => $product, 'plan' => $plan, 'price' => $price] = phaseFixture();

    $this->actingAs($owner)
        ->put(route('catalog.prices.phases', [$product, $price]), [
            'phases' => [
                ['charge_price' => ['unit_amount' => 2500], 'duration_interval' => 'month', 'duration_count' => 2],
                ['charge_price' => ['unit_amount' => 5000], 'duration_interval' => 'month', 'duration_count' => 1],
            ],
        ])
        ->assertRedirect();

    $price->load('phases.chargePrice');
    expect($price->phases)->toHaveCount(2)
        ->and($price->phases->first()->duration_count)->toBe(2)
        ->and($price->phases->first()->chargePrice->unit_amount)->toBe(250000)
        // Auto-created targets are hidden from every picker, on the same plan.
        ->and($price->phases->first()->chargePrice->purchasable)->toBeFalse()
        ->and($price->phases->first()->chargePrice->plan_id)->toBe($plan->id);
});

test('a phase-only charge price never surfaces as purchasable', function () {
    ['owner' => $owner, 'product' => $product, 'price' => $price] = phaseFixture();

    $this->actingAs($owner)
        ->put(route('catalog.prices.phases', [$product, $price]), [
            'phases' => [
                ['charge_price' => ['unit_amount' => 2500], 'duration_interval' => 'month', 'duration_count' => 1],
            ],
        ])
        ->assertRedirect();

    $chargePrice = $price->phases()->firstOrFail()->chargePrice;

    expect(Price::query()->purchasableForNewSubscriptions()->pluck('id'))
        ->not->toContain($chargePrice->id);
});

test('phases cannot be authored on a price with subscribers', function () {
    ['owner' => $owner, 'team' => $team, 'product' => $product, 'plan' => $plan, 'price' => $price] = phaseFixture();

    $subscription = Subscription::factory()->for($team)->create(['currency' => 'NGN']);
    SubscriptionItem::factory()->for($subscription)->create([
        'price_id' => $price->id,
        'plan_id' => $plan->id,
        'product_id' => $product->id,
    ]);

    $this->actingAs($owner)
        ->put(route('catalog.prices.phases', [$product, $price]), [
            'phases' => [
                ['charge_price' => ['unit_amount' => 2500], 'duration_interval' => 'month', 'duration_count' => 1],
            ],
        ])
        ->assertSessionHasErrors('phases');

    expect($price->phases()->count())->toBe(0);
});
