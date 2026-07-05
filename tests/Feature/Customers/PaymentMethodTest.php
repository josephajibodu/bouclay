<?php

use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Team;
use App\Models\User;

test('a payment method can be made the default and mirrors onto the customer', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $customer = Customer::factory()->for($team)->create();
    $first = PaymentMethod::factory()->for($team)->for($customer)->default()->create();
    $second = PaymentMethod::factory()->for($team)->for($customer)->create(['is_default' => false]);
    $customer->update(['default_payment_method_id' => $first->id]);

    attachTeamOwner($team, $owner);
    $owner->switchTeam($team);

    $this->actingAs($owner)
        ->post(route('customers.payment-methods.default', [
            'customer' => $customer,
            'payment_method' => $second,
        ]))
        ->assertRedirect();

    expect($second->fresh()->is_default)->toBeTrue()
        ->and($first->fresh()->is_default)->toBeFalse()
        ->and($customer->fresh()->default_payment_method_id)->toBe($second->id);
});

test('removing the default promotes another card and clears the pointer', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $customer = Customer::factory()->for($team)->create();
    $default = PaymentMethod::factory()->for($team)->for($customer)->default()->create();
    $other = PaymentMethod::factory()->for($team)->for($customer)->create(['is_default' => false]);
    $customer->update(['default_payment_method_id' => $default->id]);

    attachTeamOwner($team, $owner);
    $owner->switchTeam($team);

    $this->actingAs($owner)
        ->delete(route('customers.payment-methods.destroy', [
            'customer' => $customer,
            'payment_method' => $default,
        ]))
        ->assertRedirect();

    expect(PaymentMethod::query()->whereKey($default->id)->exists())->toBeFalse()
        ->and($customer->fresh()->default_payment_method_id)->toBe($other->id)
        ->and($other->fresh()->is_default)->toBeTrue();
});

test('an expired card cannot be made the default', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $customer = Customer::factory()->for($team)->create();
    $expired = PaymentMethod::factory()->for($team)->for($customer)->create([
        'is_default' => false,
        'exp_month' => 1,
        'exp_year' => 2020,
    ]);

    attachTeamOwner($team, $owner);
    $owner->switchTeam($team);

    $this->actingAs($owner)
        ->post(route('customers.payment-methods.default', [
            'customer' => $customer,
            'payment_method' => $expired,
        ]))
        ->assertStatus(422);
});
