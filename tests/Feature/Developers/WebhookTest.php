<?php

use App\Enums\ApiKeyMode;
use App\Models\Team;
use App\Models\TeamProcessorConnection;
use App\Models\User;
use App\Services\Gateways\FakeGateway;
use App\Services\Gateways\GatewayManager;
use App\Services\Gateways\Nomba\NombaCredentials;
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

    // Read back through the driver's own credential reader — the blob key is
    // Nomba's business, not the model's.
    expect(NombaCredentials::fromConnection($connection->refresh(), ApiKeyMode::Test)->webhookSecret)
        ->toBe('whsec_1234567890');
    expect(NombaCredentials::fromConnection($connection, ApiKeyMode::Live)?->webhookSecret)->toBeNull();
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

/*
|--------------------------------------------------------------------------
| The signing-secret section is manifest-driven (IMPLEMENTATION_V2 §V2-4)
|--------------------------------------------------------------------------
|
| Whether a gateway needs a separate signing secret — and what it's called —
| is the driver's answer. The page asks; it doesn't assume.
*/

test('the webhooks page renders the signing secret field from the driver manifest', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    attachTeamOwner($team, $owner);
    $owner->switchTeam($team);
    TeamProcessorConnection::factory()->for($team)->testConnected()->create();

    $this->actingAs($owner)
        ->get(route('developers.webhooks.show'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('connection.gatewayLabel', 'Nomba')
            ->where('connection.signingSecretField.key', 'webhook_secret')
            ->where('connection.signingSecretField.label', 'Webhook signing secret')
            ->where('connection.signingSecretField.secret', true),
        );
});

test('a gateway that needs no signing secret is told so, not asked for one', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    attachTeamOwner($team, $owner);
    $owner->switchTeam($team);
    TeamProcessorConnection::factory()->for($team)->testConnected()->create();

    // FakeGateway signs with credentials it already holds — like Paystack,
    // which HMACs the raw body with its secret key.
    app(GatewayManager::class)->extend('nomba', FakeGateway::class);

    $this->actingAs($owner)
        ->get(route('developers.webhooks.show'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('connection.signingSecretField', null)
            ->where('connection.testSecretSet', false)
            ->where('connection.gatewayLabel', 'Fake Gateway'),
        );

    // And saving one is refused outright rather than silently stored under a
    // key the driver will never read.
    $this->actingAs($owner)
        ->post(route('developers.webhooks.secret'), ['mode' => 'test', 'secret' => 'whsec_ignored'])
        ->assertNotFound();
});

test('the signing secret is validated by the manifest field rules', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    attachTeamOwner($team, $owner);
    $owner->switchTeam($team);
    TeamProcessorConnection::factory()->for($team)->testConnected()->create();

    // Nomba's manifest declares min:8 for this field — the controller reads
    // that rule rather than restating it.
    $this->actingAs($owner)
        ->post(route('developers.webhooks.secret'), ['mode' => 'test', 'secret' => 'short'])
        ->assertSessionHasErrors('secret');
});
