<?php

use App\Models\Customer;
use App\Models\Product;

test('idempotency key replays identical post responses', function () {
    ['token' => $token] = apiAuthFixture();

    $headers = apiHeaders($token, 'idem-replay-1');
    $payload = ['email' => 'replay@example.com', 'name' => 'Replay User'];

    $first = $this->postJson('/api/v1/customers', $payload, $headers);
    $first->assertCreated();

    $second = $this->postJson('/api/v1/customers', $payload, $headers);
    $second->assertStatus($first->status())
        ->assertExactJson($first->json());
});

test('idempotency key rejects different body with same key', function () {
    ['token' => $token] = apiAuthFixture();

    $key = 'idem-conflict-1';

    $this->postJson('/api/v1/customers', [
        'email' => 'first@example.com',
    ], apiHeaders($token, $key))->assertCreated();

    $this->postJson('/api/v1/customers', [
        'email' => 'second@example.com',
    ], apiHeaders($token, $key))
        ->assertConflict()
        ->assertJsonPath('error.code', 'idempotency_conflict');
});

test('post without idempotency key returns 400', function () {
    ['token' => $token] = apiAuthFixture();

    $this->postJson('/api/v1/customers', [
        'email' => 'no-idem@example.com',
    ], apiHeaders($token))
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'invalid_field');
});

test('get requests do not require idempotency key', function () {
    ['token' => $token, 'team' => $team] = apiAuthFixture();
    Customer::factory()->for($team)->create();

    $this->getJson('/api/v1/customers', apiHeaders($token))
        ->assertOk()
        ->assertJsonStructure(['data', 'meta' => ['requestId', 'pagination']]);
});

test('idempotency key does not replay responses across endpoints', function () {
    ['token' => $token, 'team' => $team] = apiAuthFixture();
    $customer = Customer::factory()->for($team)->create();
    $product = Product::factory()->for($team)->create();

    $key = 'idem-cross-endpoint-1';
    $body = ['customData' => ['source' => 'idem-scope-test']];

    $this->patchJson('/api/v1/customers/'.$customer->public_id, $body, apiHeaders($token, $key))
        ->assertOk();

    $this->patchJson('/api/v1/products/'.$product->public_id, $body, apiHeaders($token, $key))
        ->assertConflict()
        ->assertJsonPath('error.code', 'idempotency_conflict');

    expect($product->fresh()->custom_data)->not->toBe(['source' => 'idem-scope-test']);
});
