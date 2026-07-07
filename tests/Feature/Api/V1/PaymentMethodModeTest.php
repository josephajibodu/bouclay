<?php

use App\Enums\ApiKeyKind;
use App\Enums\ApiKeyMode;
use App\Models\ApiKey;
use App\Models\Customer;
use App\Models\PaymentMethod;

test('payment method list and delete are scoped to the api key mode', function () {
    ['token' => $testToken, 'team' => $team, 'apiKey' => $testApiKey] = apiAuthFixture(ApiKeyMode::Test);

    $liveGenerated = ApiKey::generate(ApiKeyMode::Live, ApiKeyKind::Secret);

    ApiKey::factory()->create([
        'team_id' => $team->id,
        'created_by' => $testApiKey->created_by,
        'mode' => ApiKeyMode::Live,
        'kind' => ApiKeyKind::Secret,
        'hashed_secret' => $liveGenerated['hashedSecret'],
        'last_four' => $liveGenerated['lastFour'],
    ]);

    $liveToken = $liveGenerated['key'];

    $customer = Customer::factory()->for($team)->create();

    $testMethod = PaymentMethod::factory()->for($team)->for($customer)->create([
        'custom_data' => ['mode' => 'test'],
    ]);

    $liveMethod = PaymentMethod::factory()->for($team)->for($customer)->create([
        'custom_data' => ['mode' => 'live'],
    ]);

    $this->getJson("/api/v1/customers/{$customer->public_id}/payment-methods", apiHeaders($testToken))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $testMethod->public_id);

    $this->getJson("/api/v1/customers/{$customer->public_id}/payment-methods/{$liveMethod->public_id}", apiHeaders($testToken))
        ->assertNotFound();

    $this->deleteJson("/api/v1/customers/{$customer->public_id}/payment-methods/{$liveMethod->public_id}", [], apiHeaders($testToken, 'pm-delete-live-with-test'))
        ->assertNotFound();

    expect(PaymentMethod::query()->find($liveMethod->id))->not->toBeNull();

    $this->getJson("/api/v1/customers/{$customer->public_id}/payment-methods", apiHeaders($liveToken))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $liveMethod->public_id);
});

test('live api key includes legacy payment methods with missing mode', function () {
    ['token' => $liveToken, 'team' => $team] = apiAuthFixture(ApiKeyMode::Live);

    $customer = Customer::factory()->for($team)->create();

    $legacyMethod = PaymentMethod::factory()->for($team)->for($customer)->create([
        'custom_data' => null,
    ]);

    $this->getJson("/api/v1/customers/{$customer->public_id}/payment-methods", apiHeaders($liveToken))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $legacyMethod->public_id);

    $this->getJson("/api/v1/customers/{$customer->public_id}/payment-methods/{$legacyMethod->public_id}", apiHeaders($liveToken))
        ->assertOk();
});
