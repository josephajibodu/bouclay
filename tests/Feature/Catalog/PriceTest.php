<?php

use App\Models\Price;
use App\Models\Product;
use App\Models\Team;
use App\Models\User;

test('a price can be added to an existing product', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $product = Product::factory()->for($team)->create();

    attachTeamOwner($team, $owner);
    $owner->switchTeam($team);

    $this
        ->actingAs($owner)
        ->post(route('catalog.prices.store', [$team, $product]), [
            'type' => 'recurring',
            'pricing_model' => 'standard',
            'unit_amount' => 150000,
            'billing_interval' => 'year',
            'billing_frequency' => 1,
        ])
        ->assertRedirect();

    expect($product->prices()->count())->toBe(1);
    expect($product->prices()->first()->unit_amount)->toBe(15000000);
});

test('a graduated price requires tiers', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $product = Product::factory()->for($team)->create();

    attachTeamOwner($team, $owner);
    $owner->switchTeam($team);

    $this
        ->actingAs($owner)
        ->post(route('catalog.prices.store', [$team, $product]), [
            'type' => 'recurring',
            'pricing_model' => 'graduated',
            'billing_interval' => 'month',
            'tiers' => [
                ['up_to' => 100, 'unit_amount' => 10],
                ['up_to' => null, 'unit_amount' => 5],
            ],
        ])
        ->assertRedirect();

    $price = $product->prices()->firstOrFail();
    expect($price->unit_amount)->toBeNull()
        ->and($price->tiers)->toHaveCount(2)
        ->and($price->tiers->first()->unit_amount)->toBe(1000)
        ->and($price->tiers->last()->up_to)->toBeNull();
});

test('a price can be archived', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $product = Product::factory()->for($team)->create();
    $price = Price::factory()->for($team)->for($product)->create();

    attachTeamOwner($team, $owner);
    $owner->switchTeam($team);

    $this
        ->actingAs($owner)
        ->delete(route('catalog.prices.archive', [$team, $product, $price]))
        ->assertRedirect();

    expect($price->fresh()->status->value)->toBe('archived');
});
