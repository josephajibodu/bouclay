<?php

use App\Enums\BusinessType;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('registration screen can be rendered', function () {
    $response = $this->get(route('register'));

    $response->assertOk();
});

test('registration screen includes team invitation context', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create(['name' => 'Laravel Team']);
    attachTeamOwner($team, $owner);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this->get(route('register', ['invitation' => $invitation->code]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('auth/register')
        ->where('teamInvitation.code', $invitation->code)
        ->where('teamInvitation.teamName', 'Laravel Team'),
    );
});

test('new users can register', function () {
    $response = $this->post(route('register.store'), [
        'first_name' => 'Test',
        'last_name' => 'User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'business_name' => 'Acme Inc',
        'business_type' => 'individual',
        'website' => 'https://acme.test',
        'country' => 'NG',
        'line1' => '1 Broad Street',
        'line2' => null,
        'city' => 'Lagos',
        'postal_code' => '100001',
    ]);

    $this->assertAuthenticated();

    $user = User::where('email', 'test@example.com')->first();
    $response->assertRedirect(route('dashboard'));

    expect($user->first_name)->toBe('Test');
    expect($user->last_name)->toBe('User');

    $team = $user->currentTeam;
    expect($team->name)->toBe('Acme Inc');
    expect($team->business_type)->toBe(BusinessType::Individual);
    expect($team->country)->toBe('NG');
});

test('registering through a valid invitation joins the inviting team instead of creating a business', function () {
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

    $response = $this->post(route('register.store'), [
        'first_name' => 'Invited',
        'last_name' => 'User',
        'email' => 'invited@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'invitation' => $invitation->code,
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard'));

    $user = User::where('email', 'invited@example.com')->first();

    expect($user->belongsToTeam($team))->toBeTrue();
    expect($user->current_team_id)->toBe($team->id);
    expect($user->teamRole($team)->id)->toBe($developerRole->id);
    expect($user->ownsTeam($team))->toBeFalse();
    expect($user->personalTeam())->toBeNull();
    expect($user->teams()->count())->toBe(1);
    expect($invitation->fresh()->accepted_at)->not->toBeNull();
});

test('registering with an expired invitation fails validation and creates no user', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    attachTeamOwner($team, $owner);

    $invitation = TeamInvitation::factory()->expired()->create([
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this->post(route('register.store'), [
        'first_name' => 'Invited',
        'last_name' => 'User',
        'email' => 'invited@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'invitation' => $invitation->code,
    ]);

    $response->assertSessionHasErrors('invitation');
    $this->assertGuest();

    $this->assertDatabaseMissing('users', ['email' => 'invited@example.com']);
});

test('registering with an invitation sent to a different email fails validation', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    attachTeamOwner($team, $owner);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this->post(route('register.store'), [
        'first_name' => 'Someone',
        'last_name' => 'Else',
        'email' => 'someone-else@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'invitation' => $invitation->code,
    ]);

    $response->assertSessionHasErrors('invitation');
    $this->assertGuest();

    $this->assertDatabaseMissing('users', ['email' => 'someone-else@example.com']);
});
