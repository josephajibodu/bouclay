<?php

use App\Enums\ApiKeyMode;
use App\Models\Team;
use App\Models\TeamProcessorConnection;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

test('the nomba integration page can be rendered', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();

    attachTeamOwner($team, $owner);
    $owner->switchTeam($team);

    $response = $this
        ->actingAs($owner)
        ->get(route('developers.nomba.show'));

    $response
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('developers/nomba')
            ->where('connection.test.connected', false)
            ->where('connection.live.connected', false)
            ->where('canManage', true),
        );
});

test('members without integrations permission cannot view the nomba page', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();

    attachTeamOwner($team, $owner);
    attachTeamMember($team, $member, 'Finance');
    $member->switchTeam($team);

    $response = $this
        ->actingAs($member)
        ->get(route('developers.nomba.show'));

    $response->assertForbidden();
});

test('test credentials can be connected with a valid response from nomba', function () {
    Http::fake([
        config('services.nomba.sandbox_url').'/v1/auth/token/issue' => Http::response([
            'code' => '00',
            'description' => 'Success',
            'data' => ['access_token' => 'fake-token', 'refresh_token' => 'fake-refresh', 'expiresAt' => now()->addMinutes(30)->toISOString()],
        ]),
    ]);

    $owner = User::factory()->create();
    $team = Team::factory()->create();

    attachTeamOwner($team, $owner);
    $owner->switchTeam($team);

    $response = $this
        ->actingAs($owner)
        ->post(route('developers.nomba.connect'), [
            'mode' => 'test',
            'account_id' => 'account-123',
            'client_id' => 'client-123',
            'client_secret' => 'secret-123',
            'webhook_secret' => 'whsec_1234567890',
        ]);

    $response->assertRedirect(route('developers.nomba.show'));

    $connection = TeamProcessorConnection::where('team_id', $team->id)->firstOrFail();

    expect($connection->test_connected_at)->not->toBeNull();
    expect($connection->test_credentials['account_id'])->toBe('account-123');
    expect($connection->test_credentials['client_secret'])->toBe('secret-123');
    expect($connection->webhookSecretFor(ApiKeyMode::Test))->toBe('whsec_1234567890');
    expect($connection->live_connected_at)->toBeNull();
});

test('a connection with no subaccount resolves the request account to the main account', function () {
    Http::fake([
        config('services.nomba.sandbox_url').'/v1/auth/token/issue' => Http::response([
            'code' => '00',
            'description' => 'Success',
            'data' => ['access_token' => 'fake-token', 'refresh_token' => 'fake-refresh', 'expiresAt' => now()->addMinutes(30)->toISOString()],
        ]),
    ]);

    $owner = User::factory()->create();
    $team = Team::factory()->create();

    attachTeamOwner($team, $owner);
    $owner->switchTeam($team);

    $this
        ->actingAs($owner)
        ->post(route('developers.nomba.connect'), [
            'mode' => 'test',
            'account_id' => 'account-123',
            'client_id' => 'client-123',
            'client_secret' => 'secret-123',
            'webhook_secret' => 'whsec_1234567890',
        ]);

    $connection = TeamProcessorConnection::where('team_id', $team->id)->firstOrFail();
    $credentials = $connection->credentialsFor(ApiKeyMode::Test);

    expect($credentials['subaccountId'])->toBeNull();
    expect($credentials['requestAccountId'])->toBe('account-123');

    Http::assertSent(fn (Request $request) => $request->header('accountId')[0] === 'account-123');
});

test('a subaccount id can be connected and requests are scoped to it, while authentication still uses the main account', function () {
    Http::fake([
        config('services.nomba.sandbox_url').'/v1/auth/token/issue' => Http::response([
            'code' => '00',
            'description' => 'Success',
            'data' => ['access_token' => 'fake-token', 'refresh_token' => 'fake-refresh', 'expiresAt' => now()->addMinutes(30)->toISOString()],
        ]),
    ]);

    $owner = User::factory()->create();
    $team = Team::factory()->create();

    attachTeamOwner($team, $owner);
    $owner->switchTeam($team);

    $response = $this
        ->actingAs($owner)
        ->post(route('developers.nomba.connect'), [
            'mode' => 'test',
            'account_id' => 'account-123',
            'subaccount_id' => 'subaccount-456',
            'client_id' => 'client-123',
            'client_secret' => 'secret-123',
            'webhook_secret' => 'whsec_1234567890',
        ]);

    $response->assertRedirect(route('developers.nomba.show'));

    $connection = TeamProcessorConnection::where('team_id', $team->id)->firstOrFail();
    $credentials = $connection->credentialsFor(ApiKeyMode::Test);

    expect($connection->test_credentials['subaccount_id'])->toBe('subaccount-456');
    expect($credentials['accountId'])->toBe('account-123');
    expect($credentials['subaccountId'])->toBe('subaccount-456');
    expect($credentials['requestAccountId'])->toBe('subaccount-456');

    // The token-issue call authenticates with the main account, never the subaccount.
    Http::assertSent(fn (Request $request) => $request->header('accountId')[0] === 'account-123');
});

test('disconnecting clears the subaccount id along with the rest of the credentials', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $connection = TeamProcessorConnection::factory()
        ->testConnected()
        ->withTestSubaccount()
        ->create(['team_id' => $team->id]);

    attachTeamOwner($team, $owner);
    $owner->switchTeam($team);

    $this
        ->actingAs($owner)
        ->delete(route('developers.nomba.disconnect'), ['mode' => 'test']);

    $connection->refresh();

    expect($connection->test_credentials)->toBeNull();
});

test('connecting fails when nomba rejects the credentials', function () {
    Http::fake([
        config('services.nomba.sandbox_url').'/v1/auth/token/issue' => Http::response([
            'code' => '01',
            'description' => 'Invalid credentials',
            'data' => null,
        ]),
    ]);

    $owner = User::factory()->create();
    $team = Team::factory()->create();

    attachTeamOwner($team, $owner);
    $owner->switchTeam($team);

    $response = $this
        ->actingAs($owner)
        ->post(route('developers.nomba.connect'), [
            'mode' => 'test',
            'account_id' => 'account-123',
            'client_id' => 'client-123',
            'client_secret' => 'wrong-secret',
            'webhook_secret' => 'whsec_1234567890',
        ]);

    $response->assertSessionHasErrors('client_secret');

    $this->assertDatabaseMissing('team_processor_connections', ['team_id' => $team->id]);
});

test('connecting fails gracefully when nomba is unreachable', function () {
    Http::fake([
        config('services.nomba.sandbox_url').'/v1/auth/token/issue' => fn () => throw new ConnectionException('timed out'),
    ]);

    $owner = User::factory()->create();
    $team = Team::factory()->create();

    attachTeamOwner($team, $owner);
    $owner->switchTeam($team);

    $response = $this
        ->actingAs($owner)
        ->post(route('developers.nomba.connect'), [
            'mode' => 'test',
            'account_id' => 'account-123',
            'client_id' => 'client-123',
            'client_secret' => 'secret-123',
            'webhook_secret' => 'whsec_1234567890',
        ]);

    $response->assertSessionHasErrors('client_secret');

    $this->assertDatabaseMissing('team_processor_connections', ['team_id' => $team->id]);
});

test('live credentials connect independently of test credentials', function () {
    Http::fake([
        config('services.nomba.production_url').'/v1/auth/token/issue' => Http::response([
            'code' => '00',
            'description' => 'Success',
            'data' => ['access_token' => 'fake-token', 'refresh_token' => 'fake-refresh', 'expiresAt' => now()->addMinutes(30)->toISOString()],
        ]),
    ]);

    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $connection = TeamProcessorConnection::factory()->testConnected()->create(['team_id' => $team->id]);

    attachTeamOwner($team, $owner);
    $owner->switchTeam($team);

    $response = $this
        ->actingAs($owner)
        ->post(route('developers.nomba.connect'), [
            'mode' => 'live',
            'account_id' => 'live-account-123',
            'client_id' => 'live-client-123',
            'client_secret' => 'live-secret-123',
            'webhook_secret' => 'whsec_live_1234567890',
        ]);

    $response->assertRedirect(route('developers.nomba.show'));

    $connection->refresh();

    expect($connection->live_connected_at)->not->toBeNull();
    expect($connection->test_connected_at)->not->toBeNull();
    expect($connection->test_credentials['account_id'])->not->toBeNull();
});

test('an already-connected mode can be re-verified', function () {
    Http::fake([
        config('services.nomba.sandbox_url').'/v1/auth/token/issue' => Http::response([
            'code' => '00',
            'description' => 'Success',
            'data' => ['access_token' => 'fake-token', 'refresh_token' => 'fake-refresh', 'expiresAt' => now()->addMinutes(30)->toISOString()],
        ]),
    ]);

    $owner = User::factory()->create();
    $team = Team::factory()->create();
    TeamProcessorConnection::factory()->testConnected()->create(['team_id' => $team->id]);

    attachTeamOwner($team, $owner);
    $owner->switchTeam($team);

    $response = $this
        ->actingAs($owner)
        ->post(route('developers.nomba.test'), ['mode' => 'test']);

    $response->assertRedirect();
    $response->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Connection verified.']);
});

test('a mode can be disconnected without affecting the other mode', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $connection = TeamProcessorConnection::factory()
        ->testConnected()
        ->liveConnected()
        ->create(['team_id' => $team->id]);

    attachTeamOwner($team, $owner);
    $owner->switchTeam($team);

    $response = $this
        ->actingAs($owner)
        ->delete(route('developers.nomba.disconnect'), ['mode' => 'test']);

    $response->assertRedirect(route('developers.nomba.show'));

    $connection->refresh();

    expect($connection->test_connected_at)->toBeNull();
    expect($connection->test_credentials)->toBeNull();
    expect($connection->live_connected_at)->not->toBeNull();
});

test('connecting requires a webhook secret', function () {
    Http::fake([
        config('services.nomba.sandbox_url').'/v1/auth/token/issue' => Http::response([
            'code' => '00',
            'description' => 'Success',
            'data' => ['access_token' => 'fake-token', 'refresh_token' => 'fake-refresh', 'expiresAt' => now()->addMinutes(30)->toISOString()],
        ]),
    ]);

    $owner = User::factory()->create();
    $team = Team::factory()->create();

    attachTeamOwner($team, $owner);
    $owner->switchTeam($team);

    $response = $this
        ->actingAs($owner)
        ->post(route('developers.nomba.connect'), [
            'mode' => 'test',
            'account_id' => 'account-123',
            'client_id' => 'client-123',
            'client_secret' => 'secret-123',
        ]);

    $response->assertSessionHasErrors('webhook_secret');
});

test('members without integrations.manage permission cannot connect nomba', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();

    attachTeamOwner($team, $owner);
    attachTeamMember($team, $member, 'Finance');
    $member->switchTeam($team);

    $response = $this
        ->actingAs($member)
        ->post(route('developers.nomba.connect'), [
            'mode' => 'test',
            'account_id' => 'account-123',
            'client_id' => 'client-123',
            'client_secret' => 'secret-123',
            'webhook_secret' => 'whsec_1234567890',
        ]);

    $response->assertForbidden();
});
