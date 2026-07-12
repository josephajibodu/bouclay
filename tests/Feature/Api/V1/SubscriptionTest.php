<?php

use App\Enums\ApiKeyMode;
use App\Enums\OutboundEventType;
use App\Enums\SubscriptionStatus;
use App\Models\Customer;
use App\Models\Event;
use App\Models\PaymentMethod;
use App\Models\Price;
use App\Models\Product;
use App\Models\Subscription;

test('subscription create accepts items priceId camelCase', function () {
    ['token' => $token, 'team' => $team] = apiAuthFixture();
    $customer = Customer::factory()->for($team)->create(['currency' => 'NGN']);
    $price = Price::factory()->for($team)->for(Product::factory()->for($team)->create())->create(['currency' => 'NGN']);

    $this->postJson('/api/v1/subscriptions', [
        'customer' => $customer->public_id,
        'collectionMode' => 'manual',
        'items' => [
            ['priceId' => $price->public_id, 'quantity' => 1],
        ],
    ], apiHeaders($token, 'sub-price-id-1'))
        ->assertCreated();

    expect(Subscription::query()->count())->toBe(1);
});

test('illegal lifecycle transition returns 409', function () {
    ['token' => $token, 'team' => $team] = apiAuthFixture();
    $customer = Customer::factory()->for($team)->create();
    $subscription = Subscription::factory()->for($team)->for($customer)->create([
        'status' => SubscriptionStatus::Canceled,
    ]);

    $this->postJson("/api/v1/subscriptions/{$subscription->public_id}/pause", [], apiHeaders($token, 'sub-pause-illegal'))
        ->assertConflict()
        ->assertJsonPath('error.code', 'conflict');
});

test('payment method mode mismatch with api key returns 422', function () {
    ['token' => $token, 'team' => $team] = apiAuthFixture(ApiKeyMode::Test);
    $customer = Customer::factory()->for($team)->create(['currency' => 'NGN']);
    $price = Price::factory()->for($team)->for(Product::factory()->for($team)->create())->create(['currency' => 'NGN']);

    $paymentMethod = PaymentMethod::factory()->for($team)->for($customer)->create([
        'custom_data' => ['mode' => 'live'],
    ]);

    $this->postJson('/api/v1/subscriptions', [
        'customer' => $customer->public_id,
        'collectionMode' => 'automatic',
        'paymentMethod' => $paymentMethod->public_id,
        'items' => [
            ['price' => $price->public_id, 'quantity' => 1],
        ],
    ], apiHeaders($token, 'sub-pm-mismatch'))
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'invalid_field');
});

test('happy path creates customer subscribes and emits subscription created event', function () {
    ['token' => $token, 'team' => $team] = apiAuthFixture();
    $price = Price::factory()->for($team)->for(Product::factory()->for($team)->create())->create(['currency' => 'NGN']);

    $customerResponse = $this->postJson('/api/v1/customers', [
        'email' => 'happy@example.com',
        'currency' => 'NGN',
    ], apiHeaders($token, 'happy-customer'));

    $customerResponse->assertCreated();
    $customerPublicId = $customerResponse->json('data.id');

    $this->postJson('/api/v1/subscriptions', [
        'customer' => $customerPublicId,
        'collectionMode' => 'manual',
        'items' => [
            ['price' => $price->public_id],
        ],
    ], apiHeaders($token, 'happy-subscription'))
        ->assertCreated()
        ->assertJsonPath('data.status', SubscriptionStatus::Incomplete->value);

    expect(Event::query()
        ->where('team_id', $team->id)
        ->where('type', OutboundEventType::SubscriptionCreated)
        ->exists())->toBeTrue();
});
