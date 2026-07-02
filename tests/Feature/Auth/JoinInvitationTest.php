<?php

use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('the invitation landing page shows invitation details for a guest', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create(['name' => 'Laravel Team']);
    attachTeamOwner($team, $owner);

    $developerRole = $team->roles()->where('name', 'Developer')->firstOrFail();

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
        'role_id' => $developerRole->id,
    ]);

    $response = $this->get(route('join.show', $invitation));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('auth/accept-invitation')
        ->where('invitation.teamName', 'Laravel Team')
        ->where('invitation.inviterName', $owner->name)
        ->where('invitation.roleName', 'Developer')
        ->where('accountExists', false)
        ->where('viewerState', 'guest'),
    );
});

test('the invitation landing page reports an existing account', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    attachTeamOwner($team, $owner);

    User::factory()->create(['email' => 'invited@example.com']);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this->get(route('join.show', $invitation));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('accountExists', true),
    );
});

test('the invitation landing page reports the correct-user viewer state', function () {
    $owner = User::factory()->create();
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);
    $team = Team::factory()->create();
    attachTeamOwner($team, $owner);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($invitedUser)
        ->get(route('join.show', $invitation));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('viewerState', 'correct-user'),
    );
});

test('the invitation landing page reports the wrong-user viewer state', function () {
    $owner = User::factory()->create();
    $uninvitedUser = User::factory()->create(['email' => 'someone-else@example.com']);
    $team = Team::factory()->create();
    attachTeamOwner($team, $owner);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($uninvitedUser)
        ->get(route('join.show', $invitation));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('viewerState', 'wrong-user')
        ->where('viewerEmail', 'someone-else@example.com'),
    );
});

test('the invitation landing page reports no invitation once expired', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    attachTeamOwner($team, $owner);

    $invitation = TeamInvitation::factory()->expired()->create([
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this->get(route('join.show', $invitation));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->where('invitation', null),
    );
});

test('guests can create an account and join the inviting team through a valid invitation', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create(['name' => 'Laravel Team']);
    attachTeamOwner($team, $owner);

    $developerRole = $team->roles()->where('name', 'Developer')->firstOrFail();

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
        'role_id' => $developerRole->id,
    ]);

    $response = $this->post(route('join.register.store', $invitation), [
        'first_name' => 'Invited',
        'last_name' => 'User',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect();

    $user = User::where('email', 'invited@example.com')->first();

    expect($user->belongsToTeam($team))->toBeTrue();
    expect($user->current_team_id)->toBe($team->id);
    expect($user->teamRole($team)->id)->toBe($developerRole->id);
    expect($user->ownsTeam($team))->toBeFalse();
    expect($user->personalTeam())->toBeNull();
    expect($user->teams()->count())->toBe(1);
    expect($invitation->fresh()->accepted_at)->not->toBeNull();
});

test('registering through an expired invitation fails and creates no user', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    attachTeamOwner($team, $owner);

    $invitation = TeamInvitation::factory()->expired()->create([
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this->post(route('join.register.store', $invitation), [
        'first_name' => 'Invited',
        'last_name' => 'User',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertSessionHasErrors('invitation');
    $this->assertGuest();

    $this->assertDatabaseMissing('users', ['email' => 'invited@example.com']);
});

test('authenticated users cannot use the guest join-register route', function () {
    $existingUser = User::factory()->create();
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    attachTeamOwner($team, $owner);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($existingUser)
        ->get(route('join.register', $invitation));

    $response->assertRedirect();
    expect($existingUser->fresh()->belongsToTeam($team))->toBeFalse();
});

test('invitations can be declined as a guest', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    attachTeamOwner($team, $owner);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this->post(route('join.decline', $invitation));

    $response->assertRedirect(route('register'));
    $response->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Invitation declined.']);

    $this->assertDatabaseMissing('team_invitations', ['id' => $invitation->id]);
});

test('logging in with a pending invitation code joins the inviting team', function () {
    $owner = User::factory()->create();
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);
    $team = Team::factory()->create(['name' => 'Laravel Team']);
    attachTeamOwner($team, $owner);

    $developerRole = $team->roles()->where('name', 'Developer')->firstOrFail();

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
        'role_id' => $developerRole->id,
    ]);

    $response = $this->post(route('login.store'), [
        'email' => 'invited@example.com',
        'password' => 'password',
        'invitation' => $invitation->code,
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect();

    expect($invitedUser->fresh()->belongsToTeam($team))->toBeTrue();
    expect($invitedUser->fresh()->current_team_id)->toBe($team->id);
    expect($invitation->fresh()->accepted_at)->not->toBeNull();
});

test('logging in with an invitation for a different email does not join the team', function () {
    $owner = User::factory()->create();
    $uninvitedUser = User::factory()->create(['email' => 'someone-else@example.com']);
    $team = Team::factory()->create();
    attachTeamOwner($team, $owner);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this->post(route('login.store'), [
        'email' => 'someone-else@example.com',
        'password' => 'password',
        'invitation' => $invitation->code,
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect();

    expect($uninvitedUser->fresh()->belongsToTeam($team))->toBeFalse();
    expect($invitation->fresh()->accepted_at)->toBeNull();
});
