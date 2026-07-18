<?php

use App\Enums\BillingInterval;
use App\Enums\CatalogStatus;
use App\Enums\PriceType;
use App\Models\Plan;
use App\Models\Price;
use App\Models\PricingJourney;
use App\Models\Product;
use App\Models\Team;
use App\Models\User;

function journeyFixture(): array
{
    $owner = User::factory()->create();
    $team = Team::factory()->create(['default_currency' => 'NGN']);
    $product = Product::factory()->for($team)->create();
    $plan = Plan::factory()->for($team)->for($product)->create();

    $introPrice = Price::factory()->for($team)->for($product)->for($plan)->phaseOnly()->create([
        'type' => PriceType::Recurring,
        'unit_amount' => 100000,
        'currency' => 'NGN',
        'billing_interval' => BillingInterval::Month,
    ]);

    $regularPrice = Price::factory()->for($team)->for($product)->for($plan)->create([
        'type' => PriceType::Recurring,
        'unit_amount' => 500000,
        'currency' => 'NGN',
        'billing_interval' => BillingInterval::Month,
    ]);

    attachTeamOwner($team, $owner);
    $owner->switchTeam($team);

    return compact('owner', 'team', 'product', 'plan', 'introPrice', 'regularPrice');
}

test('creating a journey saves its ordered steps', function () {
    ['owner' => $owner, 'product' => $product, 'introPrice' => $introPrice, 'regularPrice' => $regularPrice] = journeyFixture();

    $this->actingAs($owner)
        ->post(route('catalog.pricing-journeys.store', $product), [
            'name' => 'Starter Offer',
            'steps' => [
                ['price_id' => $introPrice->id, 'duration_interval' => 'month', 'duration_count' => 3],
                ['price_id' => $regularPrice->id],
            ],
        ])
        ->assertRedirect();

    $journey = PricingJourney::query()->where('product_id', $product->id)->firstOrFail();
    $journey->load('steps.price');

    expect($journey->steps)->toHaveCount(2)
        ->and($journey->steps->first()->duration_count)->toBe(3)
        ->and($journey->steps->first()->price_id)->toBe($introPrice->id)
        ->and($journey->steps->last()->price_id)->toBe($regularPrice->id)
        ->and($journey->steps->last()->duration_interval)->toBeNull()
        ->and($journey->status)->toBe(CatalogStatus::Active)
        ->and($journey->describe())->toContain('then');
});

test('a step referencing a price from a different product is rejected', function () {
    ['owner' => $owner, 'product' => $product, 'team' => $team, 'introPrice' => $introPrice] = journeyFixture();

    $otherProduct = Product::factory()->for($team)->create();
    $otherPlan = Plan::factory()->for($team)->for($otherProduct)->create();
    $foreignPrice = Price::factory()->for($team)->for($otherProduct)->for($otherPlan)->create([
        'type' => PriceType::Recurring,
        'unit_amount' => 500000,
        'currency' => 'NGN',
        'billing_interval' => BillingInterval::Month,
    ]);

    $this->actingAs($owner)
        ->post(route('catalog.pricing-journeys.store', $product), [
            'name' => 'Cross-product journey',
            'steps' => [
                ['price_id' => $introPrice->id, 'duration_interval' => 'month', 'duration_count' => 1],
                ['price_id' => $foreignPrice->id],
            ],
        ])
        ->assertSessionHasErrors('steps');

    expect(PricingJourney::query()->where('product_id', $product->id)->count())->toBe(0);
});

test('a step without a duration that is not the last step is rejected', function () {
    ['owner' => $owner, 'product' => $product, 'introPrice' => $introPrice, 'regularPrice' => $regularPrice] = journeyFixture();

    $this->actingAs($owner)
        ->post(route('catalog.pricing-journeys.store', $product), [
            'name' => 'Malformed journey',
            'steps' => [
                ['price_id' => $introPrice->id],
                ['price_id' => $regularPrice->id],
            ],
        ])
        ->assertSessionHasErrors('steps');

    expect(PricingJourney::query()->where('product_id', $product->id)->count())->toBe(0);
});

test('editing a journey is always allowed, even after it has been copied into a schedule', function () {
    ['owner' => $owner, 'product' => $product, 'introPrice' => $introPrice, 'regularPrice' => $regularPrice] = journeyFixture();

    $this->actingAs($owner)->post(route('catalog.pricing-journeys.store', $product), [
        'name' => 'Starter Offer',
        'steps' => [
            ['price_id' => $introPrice->id, 'duration_interval' => 'month', 'duration_count' => 1],
            ['price_id' => $regularPrice->id],
        ],
    ]);

    $journey = PricingJourney::query()->where('product_id', $product->id)->firstOrFail();

    $this->actingAs($owner)
        ->patch(route('catalog.pricing-journeys.update', [$product, $journey]), [
            'name' => 'Starter Offer v2',
            'steps' => [
                ['price_id' => $introPrice->id, 'duration_interval' => 'month', 'duration_count' => 2],
                ['price_id' => $regularPrice->id],
            ],
        ])
        ->assertRedirect();

    $journey->refresh();
    expect($journey->name)->toBe('Starter Offer v2')
        ->and($journey->steps()->firstWhere('sequence', 0)?->duration_count)->toBe(2);
});

test('archiving a journey hides it without deleting its steps', function () {
    ['owner' => $owner, 'product' => $product, 'introPrice' => $introPrice, 'regularPrice' => $regularPrice] = journeyFixture();

    $this->actingAs($owner)->post(route('catalog.pricing-journeys.store', $product), [
        'name' => 'Starter Offer',
        'steps' => [
            ['price_id' => $introPrice->id, 'duration_interval' => 'month', 'duration_count' => 1],
            ['price_id' => $regularPrice->id],
        ],
    ]);

    $journey = PricingJourney::query()->where('product_id', $product->id)->firstOrFail();

    $this->actingAs($owner)
        ->delete(route('catalog.pricing-journeys.archive', [$product, $journey]))
        ->assertRedirect();

    $journey->refresh();
    expect($journey->status)->toBe(CatalogStatus::Archived)
        ->and($journey->steps()->count())->toBe(2);
});
