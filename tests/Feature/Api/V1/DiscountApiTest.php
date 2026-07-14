<?php

use App\Enums\DiscountType;
use App\Models\Discount;

test('discounts can be listed via api', function () {
    ['token' => $token, 'team' => $team] = apiAuthFixture();
    Discount::factory()->for($team)->create(['code' => 'SAVE10']);
    Discount::factory()->for($team)->create(['code' => 'SAVE20', 'active' => false]);

    $this->getJson('/api/v1/discounts', apiHeaders($token))
        ->assertOk()
        ->assertJsonCount(2, 'data');

    // The `active` filter narrows the list.
    $this->getJson('/api/v1/discounts?active=true', apiHeaders($token))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.code', 'SAVE10');
});

test('a single discount can be fetched via api with amounts in major units', function () {
    ['token' => $token, 'team' => $team] = apiAuthFixture();
    $discount = Discount::factory()->for($team)->flat(100000, 'NGN')->create(['code' => 'FLAT']);

    $this->getJson("/api/v1/discounts/{$discount->public_id}", apiHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.code', 'FLAT')
        ->assertJsonPath('data.type', DiscountType::Flat->value)
        // 100000 minor units → 1000 major.
        ->assertJsonPath('data.amount', 1000);
});

test('a discount from another team is not reachable via api', function () {
    ['token' => $token] = apiAuthFixture();
    $foreign = Discount::factory()->create();

    $this->getJson("/api/v1/discounts/{$foreign->public_id}", apiHeaders($token))
        ->assertNotFound();
});
