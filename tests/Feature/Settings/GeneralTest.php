<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('the business settings page can be rendered for the current team', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    $user->switchTeam($team);

    $response = $this
        ->actingAs($user)
        ->get(route('general.edit'));

    $response
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/general')
            ->where('team.name', $team->name),
        );
});

test('users without a current team cannot access business settings', function () {
    $user = User::factory()->create();
    $user->update(['current_team_id' => null]);

    $response = $this
        ->actingAs($user->fresh())
        ->get(route('general.edit'));

    $response->assertForbidden();
});

test('business settings can be updated by owners', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create(['name' => 'Original Name']);

    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    $user->switchTeam($team);

    $response = $this
        ->actingAs($user)
        ->patch(route('general.update'), [
            'name' => 'Updated Name',
            'business_type' => 'private',
            'country' => 'NG',
            'line1' => '1 Broad Street',
            'city' => 'Lagos',
        ]);

    $response->assertRedirect(route('general.edit'));

    $this->assertDatabaseHas('teams', [
        'id' => $team->id,
        'name' => 'Updated Name',
        'business_type' => 'private',
        'country' => 'NG',
    ]);
});

test('business settings cannot be updated by members', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $member->switchTeam($team);

    $response = $this
        ->actingAs($member)
        ->patch(route('general.update'), [
            'name' => 'Updated Name',
            'business_type' => 'private',
            'country' => 'NG',
            'line1' => '1 Broad Street',
            'city' => 'Lagos',
        ]);

    $response->assertForbidden();
});
