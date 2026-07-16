<?php

use App\Models\Team;
use App\Models\TeamProcessorConnection;
use App\Models\User;
use App\Services\Gateways\FakeGateway;
use App\Services\Gateways\GatewayManager;
use Inertia\Testing\AssertableInertia as Assert;

/*
|--------------------------------------------------------------------------
| Gateway list page + default picker (IMPLEMENTATION_V2 §V2-4b)
|--------------------------------------------------------------------------
|
| Before this page, Paystack and Flutterwave had working connect pages that
| nothing linked to, and `is_default` was written once on first connect and
| never again — so a team could never move off whichever gateway they happened
| to connect first.
*/

/**
 * @param  array<string, mixed>  $attributes
 */
function connectionFor(Team $team, string $processor, array $attributes = []): TeamProcessorConnection
{
    return TeamProcessorConnection::factory()->for($team)->create([
        'processor' => $processor,
        'test_credentials' => ['secret_key' => 'sk_test_x'],
        'test_connected_at' => now(),
        // The factory claims the default flag; a partial unique index allows
        // only one per team, so callers opt in explicitly.
        'is_default' => false,
        ...$attributes,
    ]);
}

/**
 * @return array{0: User, 1: Team}
 */
function gatewayListActor(): array
{
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    attachTeamOwner($team, $owner);
    $owner->switchTeam($team);

    return [$owner, $team];
}

test('every registered gateway is listed, connected or not', function () {
    [$owner, $team] = gatewayListActor();
    connectionFor($team, 'paystack');

    $this->actingAs($owner)
        ->get(route('developers.gateways.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('developers/gateways')
            // Enumerated from the driver registry — registering a driver is
            // the whole of shipping a gateway.
            ->has('gateways', 3)
            ->where('gateways.0.processor', 'nomba')
            ->where('gateways.0.label', 'Nomba')
            ->where('gateways.0.testConnected', false)
            ->where('gateways.1.processor', 'paystack')
            ->where('gateways.1.label', 'Paystack')
            ->where('gateways.1.testConnected', true)
            ->where('gateways.2.processor', 'flutterwave')
            ->where('gateways.2.label', 'Flutterwave'),
        );
});

test('a newly registered driver appears with no change to this page', function () {
    [$owner, $team] = gatewayListActor();

    app(GatewayManager::class)
        ->extend('acme', FakeGateway::class);

    $this->actingAs($owner)
        ->get(route('developers.gateways.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('gateways', 4)
            ->where('gateways.3.processor', 'acme')
            ->where('gateways.3.label', 'Fake Gateway'),
        );
});

test('the default gateway can be moved to another connected gateway', function () {
    [$owner, $team] = gatewayListActor();
    $nomba = connectionFor($team, 'nomba', ['is_default' => true]);
    $paystack = connectionFor($team, 'paystack', ['is_default' => false]);

    $this->actingAs($owner)
        ->post(route('developers.gateways.set-default', ['processor' => 'paystack']))
        ->assertRedirect(route('developers.gateways.index'));

    // Exactly one default, and it moved.
    expect($paystack->refresh()->is_default)->toBeTrue()
        ->and($nomba->refresh()->is_default)->toBeFalse();
});

test('a gateway with no credentials cannot be made the default', function () {
    [$owner, $team] = gatewayListActor();
    connectionFor($team, 'nomba', ['is_default' => true]);
    $flutterwave = TeamProcessorConnection::factory()->for($team)->create([
        'processor' => 'flutterwave',
        'is_default' => false,
        'test_connected_at' => null,
        'live_connected_at' => null,
    ]);

    // Accepting this would fail every new checkout and read as a Bouclay bug.
    $this->actingAs($owner)
        ->post(route('developers.gateways.set-default', ['processor' => 'flutterwave']))
        ->assertNotFound();

    expect($flutterwave->refresh()->is_default)->toBeFalse();
});

test('disconnecting the default gateway promotes another connected one', function () {
    [$owner, $team] = gatewayListActor();
    $nomba = connectionFor($team, 'nomba', ['is_default' => true]);
    $paystack = connectionFor($team, 'paystack', ['is_default' => false]);

    $this->actingAs($owner)
        ->delete(route('developers.gateways.disconnect', ['processor' => 'nomba']), ['mode' => 'test'])
        ->assertRedirect();

    // Otherwise the team is left defaulted to a gateway they just
    // disconnected, and new checkouts fail for no visible reason.
    expect($nomba->refresh()->is_default)->toBeFalse()
        ->and($paystack->refresh()->is_default)->toBeTrue();
});

test('disconnecting the only gateway leaves the flag alone rather than inventing a default', function () {
    [$owner, $team] = gatewayListActor();
    $nomba = connectionFor($team, 'nomba', ['is_default' => true]);

    $this->actingAs($owner)
        ->delete(route('developers.gateways.disconnect', ['processor' => 'nomba']), ['mode' => 'test'])
        ->assertRedirect();

    expect($nomba->refresh()->hasAnyConnection())->toBeFalse()
        ->and($nomba->is_default)->toBeTrue();
});

test('a member without manage permission cannot change the default', function () {
    [, $team] = gatewayListActor();
    $member = User::factory()->create();
    attachTeamMember($team, $member, 'Support');
    $member->switchTeam($team);

    connectionFor($team, 'nomba', ['is_default' => true]);
    $paystack = connectionFor($team, 'paystack');

    $this->actingAs($member)
        ->post(route('developers.gateways.set-default', ['processor' => 'paystack']))
        ->assertForbidden();

    expect($paystack->refresh()->is_default)->toBeFalse();
});

test('the dashboard onboarding step counts any gateway, not just Nomba', function () {
    [$owner, $team] = gatewayListActor();
    connectionFor($team, 'flutterwave');

    // The step is "take payments", not "use Nomba".
    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('onboarding.gatewayConnected', true)
            ->where('onboarding.links.gateways', route('developers.gateways.index')),
        );
});
