<?php

use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Notifications\Teams\TeamInvitation as TeamInvitationNotification;
use Illuminate\Support\Facades\Notification;

test('team invitations can be created', function () {
    Notification::fake();

    $owner = User::factory()->create();
    $team = Team::factory()->create();

    attachTeamOwner($team, $owner);
    $owner->switchTeam($team);

    $developerRole = $team->roles()->where('name', 'Developer')->firstOrFail();

    $response = $this
        ->actingAs($owner)
        ->post(route('teams.invitations.store'), [
            'email' => 'invited@example.com',
            'role_id' => $developerRole->id,
        ]);

    $response->assertRedirect(route('teams.members.index'));

    $this->assertDatabaseHas('team_invitations', [
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'role_id' => $developerRole->id,
    ]);
});

test('invitation email links to the invitation landing page for existing users', function () {
    $owner = User::factory()->create();
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);
    $team = Team::factory()->create();

    attachTeamOwner($team, $owner);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => $invitedUser->email,
        'invited_by' => $owner->id,
    ]);

    $mail = (new TeamInvitationNotification($invitation))->toMail($invitedUser);

    expect($mail->actionUrl)->toBe(route('join.show', $invitation));
});

test('invitation email links to the invitation landing page for unknown users', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();

    attachTeamOwner($team, $owner);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'unknown@example.com',
        'invited_by' => $owner->id,
    ]);

    $mail = (new TeamInvitationNotification($invitation))->toMail((object) []);

    expect($mail->actionUrl)->toBe(route('join.show', $invitation));
});

test('team invitations can be created by users with members.manage permission', function () {
    Notification::fake();

    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $team = Team::factory()->create();

    attachTeamOwner($team, $owner);
    attachTeamMember($team, $admin, 'Admin');
    $admin->switchTeam($team);

    $developerRole = $team->roles()->where('name', 'Developer')->firstOrFail();

    $response = $this
        ->actingAs($admin)
        ->post(route('teams.invitations.store'), [
            'email' => 'invited@example.com',
            'role_id' => $developerRole->id,
        ]);

    $response->assertRedirect(route('teams.members.index'));
});

test('existing team members cannot be invited', function () {
    Notification::fake();

    $owner = User::factory()->create();
    $member = User::factory()->create(['email' => 'member@example.com']);
    $team = Team::factory()->create();

    attachTeamOwner($team, $owner);
    attachTeamMember($team, $member);
    $owner->switchTeam($team);

    $developerRole = $team->roles()->where('name', 'Developer')->firstOrFail();

    $response = $this
        ->actingAs($owner)
        ->post(route('teams.invitations.store'), [
            'email' => 'member@example.com',
            'role_id' => $developerRole->id,
        ]);

    $response->assertSessionHasErrors('email');
});

test('duplicate invitations cannot be created', function () {
    Notification::fake();

    $owner = User::factory()->create();
    $team = Team::factory()->create();
    attachTeamOwner($team, $owner);
    $owner->switchTeam($team);

    TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $developerRole = $team->roles()->where('name', 'Developer')->firstOrFail();

    $response = $this
        ->actingAs($owner)
        ->post(route('teams.invitations.store'), [
            'email' => 'invited@example.com',
            'role_id' => $developerRole->id,
        ]);

    $response->assertSessionHasErrors('email');
});

test('team invitations cannot be created by members without members.manage permission', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();

    attachTeamOwner($team, $owner);
    attachTeamMember($team, $member);
    $member->switchTeam($team);

    $developerRole = $team->roles()->where('name', 'Developer')->firstOrFail();

    $response = $this
        ->actingAs($member)
        ->post(route('teams.invitations.store'), [
            'email' => 'invited@example.com',
            'role_id' => $developerRole->id,
        ]);

    $response->assertForbidden();
});

test('team invitations can be cancelled by owners', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();

    attachTeamOwner($team, $owner);
    $owner->switchTeam($team);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($owner)
        ->delete(route('teams.invitations.destroy', $invitation));

    $response->assertRedirect(route('teams.members.index'));

    $this->assertDatabaseMissing('team_invitations', [
        'id' => $invitation->id,
    ]);
});

test('team invitations can be accepted', function () {
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
        ->post(route('invitations.accept', $invitation));

    $response->assertRedirect(route('dashboard'));
    $response->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Invitation accepted.']);

    expect($invitedUser->fresh()->belongsToTeam($team))->toBeTrue();
    expect($invitation->fresh()->accepted_at)->not->toBeNull();
});

test('team invitations can be declined by the invited user', function () {
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
        ->delete(route('invitations.decline', $invitation));

    $response->assertRedirect(route('dashboard'));

    $this->assertDatabaseMissing('team_invitations', [
        'id' => $invitation->id,
    ]);
});

test('team invitations cannot be declined by uninvited user', function () {
    $owner = User::factory()->create();
    $uninvitedUser = User::factory()->create(['email' => 'uninvited@example.com']);
    $team = Team::factory()->create();

    attachTeamOwner($team, $owner);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($uninvitedUser)
        ->delete(route('invitations.decline', $invitation));

    $response->assertSessionHasErrors('invitation');

    $this->assertDatabaseHas('team_invitations', [
        'id' => $invitation->id,
    ]);
});

test('accepted team invitations cannot be declined', function () {
    $owner = User::factory()->create();
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);
    $team = Team::factory()->create();

    attachTeamOwner($team, $owner);

    $invitation = TeamInvitation::factory()->accepted()->create([
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($invitedUser)
        ->delete(route('invitations.decline', $invitation));

    $response->assertSessionHasErrors('invitation');

    $this->assertDatabaseHas('team_invitations', [
        'id' => $invitation->id,
    ]);
});

test('team invitations cannot be accepted by uninvited user', function () {
    $owner = User::factory()->create();
    $uninvitedUser = User::factory()->create(['email' => 'uninvited@example.com']);
    $team = Team::factory()->create();

    attachTeamOwner($team, $owner);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($uninvitedUser)
        ->post(route('invitations.accept', $invitation));

    $response->assertSessionHasErrors('invitation');

    expect($uninvitedUser->fresh()->belongsToTeam($team))->toBeFalse();
});

test('expired invitations cannot be accepted', function () {
    $owner = User::factory()->create();
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);
    $team = Team::factory()->create();

    attachTeamOwner($team, $owner);

    $invitation = TeamInvitation::factory()->expired()->create([
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($invitedUser)
        ->post(route('invitations.accept', $invitation));

    $response->assertSessionHasErrors('invitation');

    expect($invitedUser->fresh()->belongsToTeam($team))->toBeFalse();
});
