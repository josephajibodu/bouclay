<?php

use App\Models\Permission;
use App\Models\Team;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('the team members page can be rendered for the current team', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();

    attachTeamOwner($team, $user);
    $user->switchTeam($team);

    $response = $this
        ->actingAs($user)
        ->get(route('teams.members.index'));

    $response
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('teams/members')
            ->where('members.0.role_name', 'Admin')
            ->where('members.0.is_owner', true),
        );
});

test('the team members page cannot be viewed without members.view or members.manage permission', function () {
    $owner = User::factory()->create();
    $developer = User::factory()->create();
    $team = Team::factory()->create();

    attachTeamOwner($team, $owner);
    attachTeamMember($team, $developer, 'Developer');
    $developer->switchTeam($team);

    $response = $this
        ->actingAs($developer)
        ->get(route('teams.members.index'));

    $response->assertForbidden();
});

test('the team members page can be viewed with members.view permission alone', function () {
    $owner = User::factory()->create();
    $viewer = User::factory()->create();
    $team = Team::factory()->create();

    attachTeamOwner($team, $owner);

    $viewerRole = $team->roles()->create(['name' => 'Viewer']);
    $viewerRole->permissions()->attach(
        Permission::where('name', 'members.view')->firstOrFail(),
    );
    $team->members()->attach($viewer, ['role_id' => $viewerRole->id, 'is_owner' => false]);
    $viewer->switchTeam($team);

    $response = $this
        ->actingAs($viewer)
        ->get(route('teams.members.index'));

    $response->assertOk();
});

test('member roles can be updated by users with members.manage permission', function () {
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();

    attachTeamOwner($team, $owner);
    attachTeamMember($team, $admin, 'Admin');
    attachTeamMember($team, $member, 'Developer');
    $admin->switchTeam($team);

    $financeRole = $team->roles()->where('name', 'Finance')->firstOrFail();

    $response = $this
        ->actingAs($admin)
        ->patch(route('teams.members.update', $member), [
            'role_id' => $financeRole->id,
        ]);

    $response->assertRedirect(route('teams.members.index'));

    expect($team->members()->where('user_id', $member->id)->first()->pivot->role_id)->toEqual($financeRole->id);
});

test('member roles cannot be updated without members.manage permission', function () {
    $owner = User::factory()->create();
    $developer = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();

    attachTeamOwner($team, $owner);
    attachTeamMember($team, $developer, 'Developer');
    attachTeamMember($team, $member, 'Support');
    $developer->switchTeam($team);

    $financeRole = $team->roles()->where('name', 'Finance')->firstOrFail();

    $response = $this
        ->actingAs($developer)
        ->patch(route('teams.members.update', $member), [
            'role_id' => $financeRole->id,
        ]);

    $response->assertForbidden();
});

test('team members can be removed by users with members.manage permission', function () {
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();

    attachTeamOwner($team, $owner);
    attachTeamMember($team, $admin, 'Admin');
    attachTeamMember($team, $member);
    $admin->switchTeam($team);

    $response = $this
        ->actingAs($admin)
        ->delete(route('teams.members.destroy', $member));

    $response->assertRedirect(route('teams.members.index'));

    expect($member->fresh()->belongsToTeam($team))->toBeFalse();
});

test('team members cannot be removed without members.manage permission', function () {
    $owner = User::factory()->create();
    $developer = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();

    attachTeamOwner($team, $owner);
    attachTeamMember($team, $developer, 'Developer');
    attachTeamMember($team, $member);
    $developer->switchTeam($team);

    $response = $this
        ->actingAs($developer)
        ->delete(route('teams.members.destroy', $member));

    $response->assertForbidden();
});

test('team owner cannot be removed', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();

    attachTeamOwner($team, $owner);
    $owner->switchTeam($team);

    $response = $this
        ->actingAs($owner)
        ->delete(route('teams.members.destroy', $owner));

    $response->assertForbidden();

    expect($owner->fresh()->belongsToTeam($team))->toBeTrue();
});

test('removed member current team is set to personal team', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $personalTeam = $member->personalTeam();
    $team = Team::factory()->create();

    attachTeamOwner($team, $owner);
    attachTeamMember($team, $member);
    $owner->switchTeam($team);

    $member->update(['current_team_id' => $team->id]);

    $this
        ->actingAs($owner)
        ->delete(route('teams.members.destroy', $member));

    expect($member->fresh()->current_team_id)->toEqual($personalTeam->id);
});
