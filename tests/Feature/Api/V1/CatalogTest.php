<?php

use App\Models\Price;
use App\Models\Product;
use App\Models\TrialOffer;

test('products prices and trial offers can be created and listed via api', function () {
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
    $productId = $productResponse->json('data.publicId');

    $this->getJson('/api/v1/products', apiHeaders($token))
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $price = Price::query()->where('team_id', $team->id)->firstOrFail();

    $this->getJson('/api/v1/prices/'.$price->public_id, apiHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.publicId', $price->public_id);

    $regularPrice = Price::factory()->for($team)->for(Product::find($price->product_id))->create([
        'currency' => 'NGN',
    ]);

    $trialResponse = $this->postJson("/api/v1/products/{$productId}/trial-offers", [
        'name' => '14-day trial',
        'trialPriceId' => $price->public_id,
        'transitionPriceId' => $regularPrice->public_id,
        'durationIterations' => 1,
    ], apiHeaders($token, 'trial-create-1'));

    $trialResponse->assertCreated();

    $this->getJson('/api/v1/trial-offers', apiHeaders($token))
        ->assertOk()
        ->assertJsonCount(1, 'data');

    expect(TrialOffer::query()->where('team_id', $team->id)->count())->toBe(1);
});
