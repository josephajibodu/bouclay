<?php

use App\Models\Price;
use App\Models\Product;
use App\Models\Team;
use App\Models\TrialOffer;
use App\Models\User;

test('a free trial can be added to an existing price', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $product = Product::factory()->for($team)->create();
    $price = Price::factory()->for($team)->for($product)->create();

    attachTeamOwner($team, $owner);
    $owner->switchTeam($team);

    $this
        ->actingAs($owner)
        ->post(route('catalog.trials.store', [$team, $product, $price]), [
            'duration_amount' => 30,
            'duration_unit' => 'day',
        ])
        ->assertRedirect();

    $trial = TrialOffer::query()->where('transition_price_id', $price->id)->firstOrFail();
    expect($trial->trialPrice->billing_frequency)->toBe(30)
        ->and($trial->trialPrice->billing_interval->value)->toBe('day')
        ->and($trial->trialPrice->unit_amount)->toBe(0);
});

test('a trial duration can be edited', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $trial = TrialOffer::factory()->create(['team_id' => $team->id]);
    $product = $trial->product;

    attachTeamOwner($team, $owner);
    $owner->switchTeam($team);

    $this
        ->actingAs($owner)
        ->patch(route('catalog.trials.update', [$team, $product, $trial]), [
            'duration_amount' => 7,
            'duration_unit' => 'day',
        ])
        ->assertRedirect();

    expect($trial->trialPrice->fresh()->billing_frequency)->toBe(7);
});

test('removing a trial deletes its hidden trial price', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $trial = TrialOffer::factory()->create(['team_id' => $team->id]);
    $product = $trial->product;
    $trialPriceId = $trial->trial_price_id;

    attachTeamOwner($team, $owner);
    $owner->switchTeam($team);

    $this
        ->actingAs($owner)
        ->delete(route('catalog.trials.destroy', [$team, $product, $trial]))
        ->assertRedirect();

    expect(TrialOffer::find($trial->id))->toBeNull();
    expect(Price::find($trialPriceId))->toBeNull();
});
