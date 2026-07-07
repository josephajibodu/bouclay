<?php

use App\Models\Customer;

test('customers can be created listed updated archived and restored via api', function () {
    ['token' => $token, 'team' => $team] = apiAuthFixture();

    $create = $this->postJson('/api/v1/customers', [
        'email' => 'ada@example.com',
        'name' => 'Ada Obi',
        'externalRef' => 'acct_123',
    ], apiHeaders($token, 'cust-crud-1'));

    $create->assertCreated()
        ->assertJsonPath('data.email', 'ada@example.com');

    $customerId = $create->json('data.id');
    $customer = Customer::query()->where('public_id', $customerId)->firstOrFail();

    expect($create->json('data.portalUrl'))->toBe(route('portal.show', $customer->portal_token));

    $this->getJson('/api/v1/customers', apiHeaders($token))
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->getJson("/api/v1/customers/{$customerId}", apiHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.id', $customerId);

    $this->patchJson("/api/v1/customers/{$customerId}", [
        'name' => 'Ada O.',
    ], apiHeaders($token, 'cust-crud-patch'))
        ->assertOk()
        ->assertJsonPath('data.name', 'Ada O.');

    $this->postJson("/api/v1/customers/{$customerId}/archive", [], apiHeaders($token, 'cust-crud-archive'))
        ->assertOk()
        ->assertJsonPath('data.status', 'archived');

    expect(Customer::withTrashed()->where('public_id', $customerId)->first()?->trashed())->toBeTrue();

    $this->postJson("/api/v1/customers/{$customerId}/restore", [], apiHeaders($token, 'cust-crud-restore'))
        ->assertOk()
        ->assertJsonPath('data.status', 'active');
});

test('customer addresses can be managed via api', function () {
    ['token' => $token, 'team' => $team] = apiAuthFixture();
    $customer = Customer::factory()->for($team)->create();

    $create = $this->postJson("/api/v1/customers/{$customer->public_id}/addresses", [
        'type' => 'billing',
        'line1' => '12 Marina',
        'country' => 'NG',
    ], apiHeaders($token, 'addr-create-1'));

    $create->assertCreated();
    $addressId = $create->json('data.id');

    $this->getJson("/api/v1/customers/{$customer->public_id}/addresses", apiHeaders($token))
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->getJson("/api/v1/customers/{$customer->public_id}/addresses/{$addressId}", apiHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.line1', '12 Marina');
});
