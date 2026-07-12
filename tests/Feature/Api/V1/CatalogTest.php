<?php

use App\Models\Price;

test('products and prices can be created and listed via api', function () {
    ['token' => $token, 'team' => $team] = apiAuthFixture();

    $productResponse = $this->postJson('/api/v1/products', [
        'name' => 'Pro',
        'description' => 'Pro plan',
        'price' => [
            'type' => 'recurring',
            'pricingModel' => 'standard',
            'unitAmount' => 15000,
            'currency' => 'NGN',
            'billingInterval' => 'month',
        ],
    ], apiHeaders($token, 'prod-create-1'));

    $productResponse->assertCreated();

    $this->getJson('/api/v1/products', apiHeaders($token))
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $price = Price::query()->where('team_id', $team->id)->firstOrFail();

    $this->getJson('/api/v1/prices/'.$price->public_id, apiHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.id', $price->public_id);
});
