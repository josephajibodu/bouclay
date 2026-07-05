<?php

use App\Models\Customer;
use App\Models\Team;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('the customers index page can be rendered', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    Customer::factory()->for($team)->create(['name' => 'Ada Lovelace']);

    attachTeamOwner($team, $owner);
    $owner->switchTeam($team);

    $this->actingAs($owner)
        ->get(route('customers.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('customers/index')
            ->has('customers.data', 1)
            ->where('customers.data.0.name', 'Ada Lovelace')
            ->where('canManage', true),
        );
});

test('members without the customers permission cannot view the page', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();

    attachTeamOwner($team, $owner);
    attachTeamMember($team, $member, 'Finance');
    $member->switchTeam($team);

    $this->actingAs($member)
        ->get(route('customers.index'))
        ->assertForbidden();
});

test('the list can be searched by name or email', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    Customer::factory()->for($team)->create(['name' => 'Grace Hopper', 'email' => 'grace@example.com']);
    Customer::factory()->for($team)->create(['name' => 'Alan Turing', 'email' => 'alan@example.com']);

    attachTeamOwner($team, $owner);
    $owner->switchTeam($team);

    $this->actingAs($owner)
        ->get(route('customers.index', ['search' => 'grace']))
        ->assertInertia(fn (Assert $page) => $page->has('customers.data', 1)
            ->where('customers.data.0.email', 'grace@example.com'));
});

test('the list can be filtered to archived customers', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    Customer::factory()->for($team)->create(['name' => 'Active Person']);
    Customer::factory()->for($team)->trashed()->create(['name' => 'Archived Person']);

    attachTeamOwner($team, $owner);
    $owner->switchTeam($team);

    $this->actingAs($owner)
        ->get(route('customers.index', ['status' => 'archived']))
        ->assertInertia(fn (Assert $page) => $page->has('customers.data', 1)
            ->where('customers.data.0.status', 'archived'));
});

test('a customer can be created with just an email', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();

    attachTeamOwner($team, $owner);
    $owner->switchTeam($team);

    $response = $this->actingAs($owner)
        ->post(route('customers.store'), ['email' => 'new@example.com']);

    $customer = Customer::query()->where('email', 'new@example.com')->firstOrFail();

    $response->assertRedirect(route('customers.show', $customer));
    expect($customer->name)->toBeNull()
        ->and($customer->public_id)->toStartWith('ctm_');
});

test('creating a customer requires a valid email', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();

    attachTeamOwner($team, $owner);
    $owner->switchTeam($team);

    $this->actingAs($owner)
        ->post(route('customers.store'), ['name' => 'No Email'])
        ->assertSessionHasErrors('email');
});

test('external reference is unique within a team', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    Customer::factory()->for($team)->create(['external_ref' => 'cus_1']);

    attachTeamOwner($team, $owner);
    $owner->switchTeam($team);

    $this->actingAs($owner)
        ->post(route('customers.store'), ['email' => 'dup@example.com', 'external_ref' => 'cus_1'])
        ->assertSessionHasErrors('external_ref');
});

test('a customer detail page renders its sections', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $customer = Customer::factory()->for($team)->create();

    attachTeamOwner($team, $owner);
    $owner->switchTeam($team);

    $this->actingAs($owner)
        ->get(route('customers.show', $customer))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('customers/show')
            ->where('customer.publicId', $customer->public_id)
            ->has('paymentMethods')
            ->has('addresses')
            ->has('activity'),
        );
});

test('a customer can be updated', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $customer = Customer::factory()->for($team)->create();

    attachTeamOwner($team, $owner);
    $owner->switchTeam($team);

    $this->actingAs($owner)
        ->patch(route('customers.update', $customer), [
            'email' => 'updated@example.com',
            'name' => 'Updated Name',
        ])
        ->assertRedirect();

    expect($customer->fresh()->email)->toBe('updated@example.com')
        ->and($customer->fresh()->name)->toBe('Updated Name');
});

test('a customer can be archived and restored', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $customer = Customer::factory()->for($team)->create();

    attachTeamOwner($team, $owner);
    $owner->switchTeam($team);

    $this->actingAs($owner)
        ->delete(route('customers.archive', $customer))
        ->assertRedirect(route('customers.index'));

    expect($customer->fresh()->trashed())->toBeTrue();

    $this->actingAs($owner)
        ->post(route('customers.restore', $customer))
        ->assertRedirect();

    expect($customer->fresh()->trashed())->toBeFalse();
});

test('customers can be bulk archived', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $a = Customer::factory()->for($team)->create();
    $b = Customer::factory()->for($team)->create();

    attachTeamOwner($team, $owner);
    $owner->switchTeam($team);

    $this->actingAs($owner)
        ->post(route('customers.bulk-archive'), ['ids' => [$a->id, $b->id]])
        ->assertRedirect(route('customers.index'));

    expect($a->fresh()->trashed())->toBeTrue()
        ->and($b->fresh()->trashed())->toBeTrue();
});

test('a customer from another team cannot be viewed', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $otherCustomer = Customer::factory()->create();

    attachTeamOwner($team, $owner);
    $owner->switchTeam($team);

    $this->actingAs($owner)
        ->get(route('customers.show', $otherCustomer))
        ->assertNotFound();
});
