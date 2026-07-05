<?php

use App\Models\Address;
use App\Models\Customer;
use App\Models\Team;
use App\Models\User;

test('an address can be added and the first is default for its type', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $customer = Customer::factory()->for($team)->create();

    attachTeamOwner($team, $owner);
    $owner->switchTeam($team);

    $this->actingAs($owner)
        ->post(route('customers.addresses.store', $customer), [
            'type' => 'billing',
            'country' => 'NG',
            'line1' => 'Akobo, Ibadan',
            'city' => 'Ibadan',
            'is_default' => '0',
        ])
        ->assertRedirect();

    $address = $customer->addresses()->firstOrFail();
    expect($address->is_default)->toBeTrue()
        ->and($address->country)->toBe('NG');
});

test('setting a new default demotes the previous default of the same type', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $customer = Customer::factory()->for($team)->create();
    $first = Address::factory()->for($team)->for($customer)->default()->create();
    $second = Address::factory()->for($team)->for($customer)->create(['is_default' => false]);

    attachTeamOwner($team, $owner);
    $owner->switchTeam($team);

    $this->actingAs($owner)
        ->post(route('customers.addresses.default', ['customer' => $customer, 'address' => $second]))
        ->assertRedirect();

    expect($second->fresh()->is_default)->toBeTrue()
        ->and($first->fresh()->is_default)->toBeFalse();
});

test('an address can be removed', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $customer = Customer::factory()->for($team)->create();
    $address = Address::factory()->for($team)->for($customer)->create();

    attachTeamOwner($team, $owner);
    $owner->switchTeam($team);

    $this->actingAs($owner)
        ->delete(route('customers.addresses.destroy', ['customer' => $customer, 'address' => $address]))
        ->assertRedirect();

    expect(Address::query()->whereKey($address->id)->exists())->toBeFalse();
});

test('an address from another customer cannot be edited through this customer', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $customer = Customer::factory()->for($team)->create();
    $otherCustomer = Customer::factory()->for($team)->create();
    $address = Address::factory()->for($team)->for($otherCustomer)->create();

    attachTeamOwner($team, $owner);
    $owner->switchTeam($team);

    $this->actingAs($owner)
        ->delete(route('customers.addresses.destroy', ['customer' => $customer, 'address' => $address]))
        ->assertNotFound();
});
