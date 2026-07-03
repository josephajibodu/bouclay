<?php

use App\Models\Price;
use App\Models\Product;
use App\Models\Team;
use App\Models\TrialOffer;
use App\Models\User;

test('a trial can be created from two existing prices on the same product', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $product = Product::factory()->for($team)->create();
    $trialPrice = Price::factory()->for($team)->for($product)->free()->create();
    $regularPrice = Price::factory()->for($team)->for($product)->create();

    attachTeamOwner($team, $owner);
    $owner->switchTeam($team);

    $this
        ->actingAs($owner)
        ->post(route('catalog.trials.store', [$team, $product]), [
            'name' => 'Free trial offer',
            'trial_price_id' => $trialPrice->id,
            'transition_to_different_product' => false,
            'transition_price_id' => $regularPrice->id,
            'duration_iterations' => 1,
        ])
        ->assertRedirect();

    $trial = TrialOffer::query()->where('product_id', $product->id)->firstOrFail();
    expect($trial->name)->toBe('Free trial offer')
        ->and($trial->trial_price_id)->toBe($trialPrice->id)
        ->and($trial->transition_price_id)->toBe($regularPrice->id)
        ->and($trial->transition_to_different_product)->toBeFalse()
        ->and($trial->transition_product_id)->toBe($product->id)
        ->and($trial->duration_iterations)->toBe(1);

    // Both prices stay normal, visible catalog prices — neither is hidden.
    expect($product->prices()->pluck('id'))
        ->toContain($trialPrice->id)
        ->toContain($regularPrice->id);
});

test('a trial can transition to a different product', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $product = Product::factory()->for($team)->create();
    $trialPrice = Price::factory()->for($team)->for($product)->free()->create();
    $otherProduct = Product::factory()->for($team)->create();
    $otherPrice = Price::factory()->for($team)->for($otherProduct)->create();

    attachTeamOwner($team, $owner);
    $owner->switchTeam($team);

    $this
        ->actingAs($owner)
        ->post(route('catalog.trials.store', [$team, $product]), [
            'name' => 'Upsell trial',
            'trial_price_id' => $trialPrice->id,
            'transition_to_different_product' => true,
            'transition_product_id' => $otherProduct->id,
            'transition_price_id' => $otherPrice->id,
            'duration_iterations' => 1,
        ])
        ->assertRedirect();

    $trial = TrialOffer::query()->where('name', 'Upsell trial')->firstOrFail();
    expect($trial->transition_to_different_product)->toBeTrue()
        ->and($trial->transition_product_id)->toBe($otherProduct->id)
        ->and($trial->transition_price_id)->toBe($otherPrice->id);
});

test('a trial rejects transitioning to a price from the wrong product', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $product = Product::factory()->for($team)->create();
    $trialPrice = Price::factory()->for($team)->for($product)->free()->create();
    $otherProduct = Product::factory()->for($team)->create();
    $otherPrice = Price::factory()->for($team)->for($otherProduct)->create();

    attachTeamOwner($team, $owner);
    $owner->switchTeam($team);

    // transition_to_different_product is false, but the price belongs to otherProduct.
    $this
        ->actingAs($owner)
        ->post(route('catalog.trials.store', [$team, $product]), [
            'name' => 'Invalid trial',
            'trial_price_id' => $trialPrice->id,
            'transition_to_different_product' => false,
            'transition_price_id' => $otherPrice->id,
            'duration_iterations' => 1,
        ])
        ->assertNotFound();
});

test('a trial requires the trial price and transition price to differ', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $product = Product::factory()->for($team)->create();
    $price = Price::factory()->for($team)->for($product)->create();

    attachTeamOwner($team, $owner);
    $owner->switchTeam($team);

    $this
        ->actingAs($owner)
        ->post(route('catalog.trials.store', [$team, $product]), [
            'name' => 'Same price trial',
            'trial_price_id' => $price->id,
            'transition_to_different_product' => false,
            'transition_price_id' => $price->id,
            'duration_iterations' => 1,
        ])
        ->assertInvalid(['transition_price_id']);
});

test('a trial can repeat for multiple iterations', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $product = Product::factory()->for($team)->create();
    $trialPrice = Price::factory()->for($team)->for($product)->create(['unit_amount' => 100]);
    $regularPrice = Price::factory()->for($team)->for($product)->create();

    attachTeamOwner($team, $owner);
    $owner->switchTeam($team);

    $this
        ->actingAs($owner)
        ->post(route('catalog.trials.store', [$team, $product]), [
            'name' => '3-month intro rate',
            'trial_price_id' => $trialPrice->id,
            'transition_to_different_product' => false,
            'transition_price_id' => $regularPrice->id,
            'duration_iterations' => 3,
        ])
        ->assertRedirect();

    expect(TrialOffer::query()->where('name', '3-month intro rate')->firstOrFail()->duration_iterations)->toBe(3);
});

test('a trial can be edited in place', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $trial = TrialOffer::factory()->create(['team_id' => $team->id]);
    $product = $trial->product;
    $newTrialPrice = Price::factory()->for($team)->for($product)->create();

    attachTeamOwner($team, $owner);
    $owner->switchTeam($team);

    $this
        ->actingAs($owner)
        ->patch(route('catalog.trials.update', [$team, $product, $trial]), [
            'name' => 'Updated trial',
            'trial_price_id' => $newTrialPrice->id,
            'transition_to_different_product' => false,
            'transition_price_id' => $trial->transition_price_id,
            'duration_iterations' => 2,
        ])
        ->assertRedirect();

    $trial->refresh();
    expect($trial->name)->toBe('Updated trial')
        ->and($trial->trial_price_id)->toBe($newTrialPrice->id)
        ->and($trial->duration_iterations)->toBe(2);
});

test('removing a trial does not delete its prices', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $trial = TrialOffer::factory()->create(['team_id' => $team->id]);
    $product = $trial->product;
    $trialPriceId = $trial->trial_price_id;
    $transitionPriceId = $trial->transition_price_id;

    attachTeamOwner($team, $owner);
    $owner->switchTeam($team);

    $this
        ->actingAs($owner)
        ->delete(route('catalog.trials.destroy', [$team, $product, $trial]))
        ->assertRedirect();

    expect(TrialOffer::find($trial->id))->toBeNull();
    expect(Price::find($trialPriceId))->not->toBeNull();
    expect(Price::find($transitionPriceId))->not->toBeNull();
});
