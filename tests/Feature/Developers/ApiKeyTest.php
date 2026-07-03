<?php

use App\Models\ApiKey;
use App\Models\Team;
use App\Models\TeamProcessorConnection;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('the api keys page can be rendered', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    ApiKey::factory()->create(['team_id' => $team->id, 'name' => 'Backend server']);

    attachTeamOwner($team, $owner);
    $owner->switchTeam($team);

    $response = $this
        ->actingAs($owner)
        ->get(route('developers.api-keys.index', $team));

    $response
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('developers/api-keys')
            ->has('keys', 1)
            ->where('keys.0.name', 'Backend server')
            ->where('canManage', true)
            ->where('liveNombaConnected', false),
        );
});

test('members without api_keys permission cannot view the page', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();

    attachTeamOwner($team, $owner);
    attachTeamMember($team, $member, 'Finance');
    $member->switchTeam($team);

    $response = $this
        ->actingAs($member)
        ->get(route('developers.api-keys.index', $team));

    $response->assertForbidden();
});

test('a test secret key can be created', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();

    attachTeamOwner($team, $owner);
    $owner->switchTeam($team);

    $response = $this
        ->actingAs($owner)
        ->post(route('developers.api-keys.store', $team), [
            'name' => 'Backend server',
            'kind' => 'secret',
            'mode' => 'test',
        ]);

    $response->assertRedirect(route('developers.api-keys.index', $team));

    $key = ApiKey::where('team_id', $team->id)->firstOrFail();

    expect($key->name)->toBe('Backend server');
    expect($key->kind->value)->toBe('secret');
    expect($key->mode->value)->toBe('test');
    expect($key->created_by)->toBe($owner->id);
    expect($key->last_four)->toHaveLength(4);
});

test('a live key cannot be created without a live nomba connection', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();

    attachTeamOwner($team, $owner);
    $owner->switchTeam($team);

    $response = $this
        ->actingAs($owner)
        ->post(route('developers.api-keys.store', $team), [
            'name' => 'Backend server',
            'kind' => 'secret',
            'mode' => 'live',
        ]);

    $response->assertSessionHasErrors('mode');

    $this->assertDatabaseMissing('api_keys', ['team_id' => $team->id]);
});

test('a live key can be created once a live nomba connection exists', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    TeamProcessorConnection::factory()->liveConnected()->create(['team_id' => $team->id]);

    attachTeamOwner($team, $owner);
    $owner->switchTeam($team);

    $response = $this
        ->actingAs($owner)
        ->post(route('developers.api-keys.store', $team), [
            'name' => 'Backend server',
            'kind' => 'secret',
            'mode' => 'live',
        ]);

    $response->assertRedirect(route('developers.api-keys.index', $team));

    $this->assertDatabaseHas('api_keys', ['team_id' => $team->id, 'mode' => 'live']);
});

test('members without api_keys.manage permission cannot create a key', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();

    attachTeamOwner($team, $owner);
    attachTeamMember($team, $member, 'Finance');
    $member->switchTeam($team);

    $response = $this
        ->actingAs($member)
        ->post(route('developers.api-keys.store', $team), [
            'name' => 'Backend server',
            'kind' => 'secret',
            'mode' => 'test',
        ]);

    $response->assertForbidden();
});

test('a key can be revoked', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $key = ApiKey::factory()->create(['team_id' => $team->id]);

    attachTeamOwner($team, $owner);
    $owner->switchTeam($team);

    $response = $this
        ->actingAs($owner)
        ->delete(route('developers.api-keys.destroy', [$team, $key]));

    $response->assertRedirect(route('developers.api-keys.index', $team));

    expect($key->refresh()->revoked_at)->not->toBeNull();
});

test('a key belonging to another team cannot be revoked', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    $key = ApiKey::factory()->create(['team_id' => $otherTeam->id]);

    attachTeamOwner($team, $owner);
    $owner->switchTeam($team);

    $response = $this
        ->actingAs($owner)
        ->delete(route('developers.api-keys.destroy', [$team, $key]));

    $response->assertNotFound();
});
