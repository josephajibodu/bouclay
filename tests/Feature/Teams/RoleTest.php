<?php

use App\Models\Permission;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('the roles page can be rendered for the current team', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();

    attachTeamOwner($team, $user);
    $user->switchTeam($team);

    $response = $this
        ->actingAs($user)
        ->get(route('roles.index'));

    $response
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('teams/roles')
            ->has('roles', 4)
            ->where('roles.0.name', 'Admin')
            ->where('roles.0.isSystem', true),
        );
});

test('the roles page cannot be viewed without roles.view or roles.manage permission', function () {
    $owner = User::factory()->create();
    $developer = User::factory()->create();
    $team = Team::factory()->create();

    attachTeamOwner($team, $owner);
    attachTeamMember($team, $developer, 'Developer');
    $developer->switchTeam($team);

    $response = $this
        ->actingAs($developer)
        ->get(route('roles.index'));

    $response->assertForbidden();
});

test('the roles page can be viewed with roles.view permission alone', function () {
    $owner = User::factory()->create();
    $viewer = User::factory()->create();
    $team = Team::factory()->create();

    attachTeamOwner($team, $owner);

    $viewerRole = $team->roles()->create(['name' => 'Viewer']);
    $viewerRole->permissions()->attach(
        Permission::where('name', 'roles.view')->firstOrFail(),
    );
    $team->members()->attach($viewer, ['role_id' => $viewerRole->id, 'is_owner' => false]);
    $viewer->switchTeam($team);

    $response = $this
        ->actingAs($viewer)
        ->get(route('roles.index'));

    $response->assertOk();
});

test('roles can be created by users with roles.manage permission', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();

    attachTeamOwner($team, $user);
    $user->switchTeam($team);

    $response = $this
        ->actingAs($user)
        ->post(route('roles.store'), [
            'name' => 'Marketing',
            'permissions' => ['products.view', 'customers.view'],
        ]);

    $response->assertRedirect(route('roles.index'));

    $this->assertDatabaseHas('roles', [
        'team_id' => $team->id,
        'name' => 'Marketing',
        'is_system' => false,
    ]);

    $role = $team->roles()->where('name', 'Marketing')->firstOrFail();

    expect($role->permissions()->pluck('name')->sort()->values()->all())
        ->toBe(['customers.view', 'products.view']);
});

test('roles cannot be created without roles.manage permission', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();

    attachTeamOwner($team, $owner);
    attachTeamMember($team, $member, 'Support');
    $member->switchTeam($team);

    $response = $this
        ->actingAs($member)
        ->post(route('roles.store'), [
            'name' => 'Marketing',
            'permissions' => [],
        ]);

    $response->assertForbidden();
});

test('roles can be updated by users with roles.manage permission', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();

    attachTeamOwner($team, $user);
    $user->switchTeam($team);

    $developerRole = $team->roles()->where('name', 'Developer')->firstOrFail();

    $response = $this
        ->actingAs($user)
        ->patch(route('roles.update', $developerRole), [
            'name' => 'Engineering',
            'permissions' => ['api_keys.view'],
        ]);

    $response->assertRedirect(route('roles.index'));

    expect($developerRole->fresh()->name)->toBe('Engineering');
    expect($developerRole->fresh()->permissions()->pluck('name')->all())->toBe(['api_keys.view']);
});

test('the admin role cannot be updated', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();

    attachTeamOwner($team, $user);
    $user->switchTeam($team);

    $adminRole = $team->roles()->where('name', 'Admin')->firstOrFail();

    $response = $this
        ->actingAs($user)
        ->patch(route('roles.update', $adminRole), [
            'name' => 'Super Admin',
            'permissions' => [],
        ]);

    $response->assertForbidden();

    expect($adminRole->fresh()->name)->toBe('Admin');
});

test('the admin role cannot be deleted', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();

    attachTeamOwner($team, $user);
    $user->switchTeam($team);

    $adminRole = $team->roles()->where('name', 'Admin')->firstOrFail();

    $response = $this
        ->actingAs($user)
        ->delete(route('roles.destroy', $adminRole));

    $response->assertForbidden();

    $this->assertDatabaseHas('roles', ['id' => $adminRole->id]);
});

test('a role with assigned members cannot be deleted', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();

    attachTeamOwner($team, $owner);
    attachTeamMember($team, $member, 'Developer');
    $owner->switchTeam($team);

    $developerRole = $team->roles()->where('name', 'Developer')->firstOrFail();

    $response = $this
        ->actingAs($owner)
        ->delete(route('roles.destroy', $developerRole));

    $response->assertSessionHasErrors('role');

    $this->assertDatabaseHas('roles', ['id' => $developerRole->id]);
});

test('a role with a pending invitation cannot be deleted', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();

    attachTeamOwner($team, $owner);
    $owner->switchTeam($team);

    $developerRole = $team->roles()->where('name', 'Developer')->firstOrFail();

    TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'role_id' => $developerRole->id,
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($owner)
        ->delete(route('roles.destroy', $developerRole));

    $response->assertSessionHasErrors('role');

    $this->assertDatabaseHas('roles', ['id' => $developerRole->id]);
});

test('roles without members can be deleted', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();

    attachTeamOwner($team, $user);
    $user->switchTeam($team);

    $developerRole = $team->roles()->where('name', 'Developer')->firstOrFail();

    $response = $this
        ->actingAs($user)
        ->delete(route('roles.destroy', $developerRole));

    $response->assertRedirect(route('roles.index'));

    $this->assertDatabaseMissing('roles', ['id' => $developerRole->id]);
});

test('a role belonging to another team cannot be updated', function () {
    $user = User::factory()->create();
    $otherTeam = Team::factory()->create();

    attachTeamOwner($otherTeam, $user);
    // $user's current team remains their own personal team, not $otherTeam.

    $otherTeamRole = $otherTeam->roles()->where('name', 'Developer')->firstOrFail();

    $response = $this
        ->actingAs($user)
        ->patch(route('roles.update', $otherTeamRole), [
            'name' => 'Hijacked',
            'permissions' => [],
        ]);

    $response->assertNotFound();

    expect($otherTeamRole->fresh()->name)->toBe('Developer');
});
