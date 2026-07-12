<?php

use App\Enums\ApiKeyMode;
use App\Models\Team;
use App\Models\TeamProcessorConnection;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('the webhooks page shows an empty state before nomba is connected', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();

    attachTeamOwner($team, $owner);
    $owner->switchTeam($team);

    $response = $this
        ->actingAs($owner)
        ->get(route('developers.webhooks.show'));

    $response
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('developers/webhooks')
            ->where('connection', null)
            ->has('endpoints', 0)
            ->has('deliveries', 0),
        );
});

test('the webhooks page shows the inbound url once nomba is connected', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $connection = TeamProcessorConnection::factory()->testConnected()->create(['team_id' => $team->id]);

    attachTeamOwner($team, $owner);
    $owner->switchTeam($team);

    $response = $this
        ->actingAs($owner)
        ->get(route('developers.webhooks.show'));

    $response
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('developers/webhooks')
            ->has('endpoints')
            ->has('deliveries')
            ->where('connection.inboundUrl', url("/webhooks/nomba/{$connection->inbound_webhook_token}"))
            ->where('connection.reachable', false)
            ->where('connection.testSecretSet', true)
            ->where('connection.liveSecretSet', false),
        );
});

test('members without webhooks permission cannot view the page', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();

    attachTeamOwner($team, $owner);
    attachTeamMember($team, $member, 'Finance');
    $member->switchTeam($team);

    $response = $this
        ->actingAs($member)
        ->get(route('developers.webhooks.show'));

    $response->assertForbidden();
});

test('a signing secret can be saved for a mode', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $connection = TeamProcessorConnection::factory()->testConnected()->create(['team_id' => $team->id]);

    attachTeamOwner($team, $owner);
    $owner->switchTeam($team);

    $response = $this
        ->actingAs($owner)
        ->post(route('developers.webhooks.secret'), [
            'mode' => 'test',
            'secret' => 'whsec_1234567890',
        ]);

    $response->assertRedirect(route('developers.webhooks.show'));

    expect($connection->refresh()->webhookSecretFor(ApiKeyMode::Test))->toBe('whsec_1234567890');
    expect($connection->webhookSecretFor(ApiKeyMode::Live))->toBeNull();
});

test('the endpoint can be rotated, invalidating the old token', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $connection = TeamProcessorConnection::factory()->testConnected()->create([
        'team_id' => $team->id,
        'webhook_verified_at' => now(),
    ]);
    $oldToken = $connection->inbound_webhook_token;

    attachTeamOwner($team, $owner);
    $owner->switchTeam($team);

    $response = $this
        ->actingAs($owner)
        ->post(route('developers.webhooks.rotate'));

    $response->assertRedirect(route('developers.webhooks.show'));

    $connection->refresh();

    expect($connection->inbound_webhook_token)->not->toBe($oldToken);
    expect($connection->webhook_verified_at)->toBeNull();
});

test('members without webhooks.manage permission cannot rotate the endpoint', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();
    TeamProcessorConnection::factory()->testConnected()->create(['team_id' => $team->id]);

    attachTeamOwner($team, $owner);
    attachTeamMember($team, $member, 'Finance');
    $member->switchTeam($team);

    $response = $this
        ->actingAs($member)
        ->post(route('developers.webhooks.rotate'));

    $response->assertForbidden();
});

test('the public inbound endpoint marks the connection reachable', function () {
    $team = Team::factory()->create();
    $connection = TeamProcessorConnection::factory()->testConnected()->create(['team_id' => $team->id]);

    expect($connection->webhook_verified_at)->toBeNull();

    $response = $this->postJson("/webhooks/nomba/{$connection->inbound_webhook_token}", [
        'event_type' => 'bouclay.test_event',
    ]);

    $response->assertOk()->assertJson(['received' => true]);

    expect($connection->refresh()->webhook_verified_at)->not->toBeNull();
});

test('the public inbound endpoint 404s for an unknown token', function () {
    $response = $this->postJson('/webhooks/nomba/does-not-exist', []);

    $response->assertNotFound();
});
