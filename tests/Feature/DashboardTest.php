<?php

use App\Models\ApiKey;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\TeamProcessorConnection;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected to the login page', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $response = $this
        ->actingAs($user)
        ->get(route('dashboard'));

    $response->assertOk();
});

test('dashboard includes pending invitations for the authenticated user', function () {
    $owner = User::factory()->create(['first_name' => 'Taylor', 'last_name' => 'Otwell']);
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);
    $team = Team::factory()->create(['name' => 'Laravel Team']);

    attachTeamOwner($team, $owner);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($invitedUser)
        ->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('dashboard')
        ->has('pendingInvitations', 1)
        ->where('pendingInvitations.0.code', $invitation->code)
        ->where('pendingInvitations.0.inviterName', 'Taylor Otwell')
        ->where('pendingInvitations.0.team.name', 'Laravel Team')
        ->where('pendingInvitations.0.team.slug', $team->slug)
        ->missing('pendingInvitations.0.teamName'),
    );
});

test('dashboard does not include accepted invitations', function () {
    $owner = User::factory()->create();
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);
    $team = Team::factory()->create();

    attachTeamOwner($team, $owner);

    TeamInvitation::factory()->accepted()->create([
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($invitedUser)
        ->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('dashboard')
        ->has('pendingInvitations', 0),
    );
});

test('dashboard excludes expired invitations without deleting them', function () {
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
        ->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('dashboard')
        ->has('pendingInvitations', 0),
    );

    $this->assertDatabaseHas('team_invitations', [
        'id' => $invitation->id,
    ]);
});

test('dashboard does not include or delete other users invitations', function () {
    $owner = User::factory()->create();
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);
    $team = Team::factory()->create();

    attachTeamOwner($team, $owner);

    $invitation = TeamInvitation::factory()->expired()->create([
        'team_id' => $team->id,
        'email' => 'someone@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($invitedUser)
        ->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('dashboard')
        ->has('pendingInvitations', 0),
    );

    $this->assertDatabaseHas('team_invitations', [
        'id' => $invitation->id,
    ]);
});

test('a fresh team starts with an incomplete onboarding checklist', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get(route('dashboard'));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('dashboard')
        ->where('onboarding.businessConfirmed', false)
        ->where('onboarding.nombaConnected', false)
        ->where('onboarding.apiKeyGenerated', false)
        ->where('onboarding.webhookVerified', false),
    );
});

test('the onboarding checklist reflects completed setup steps', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $team->update(['line1' => '1 Main St', 'city' => 'Lagos', 'country' => 'NG']);
    TeamProcessorConnection::factory()
        ->testConnected()
        ->create(['team_id' => $team->id, 'webhook_verified_at' => now()]);
    ApiKey::factory()->create(['team_id' => $team->id, 'created_by' => $user->id]);

    $response = $this
        ->actingAs($user)
        ->get(route('dashboard'));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('dashboard')
        ->where('onboarding.businessConfirmed', true)
        ->where('onboarding.nombaConnected', true)
        ->where('onboarding.apiKeyGenerated', true)
        ->where('onboarding.webhookVerified', true)
        ->where('onboarding.links.nomba', route('developers.nomba.show', $team))
        ->where('onboarding.links.apiKeys', route('developers.api-keys.index', $team))
        ->where('onboarding.links.webhooks', route('developers.webhooks.show', $team)),
    );
});

test('a revoked api key does not count toward the onboarding checklist', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    ApiKey::factory()->revoked()->create(['team_id' => $team->id, 'created_by' => $user->id]);

    $response = $this
        ->actingAs($user)
        ->get(route('dashboard'));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('dashboard')
        ->where('onboarding.apiKeyGenerated', false),
    );
});
