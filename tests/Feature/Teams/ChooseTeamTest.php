<?php

use App\Models\Team;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('the choose-team page lists the users businesses', function () {
    $user = User::factory()->create();
    $otherTeam = Team::factory()->create(['name' => 'Zulu Team']);
    attachTeamOwner($otherTeam, $user);

    $response = $this
        ->actingAs($user)
        ->get(route('teams.choose'));

    $response
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/choose-team')
            ->has('teams', 2),
        );
});

test('selecting a business from the choose-team page switches the current team and reaches the dashboard', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $user->update(['current_team_id' => null]);

    $switchResponse = $this
        ->actingAs($user->fresh())
        ->post(route('teams.switch', $team));

    $switchResponse->assertRedirect();

    expect($user->fresh()->current_team_id)->toBe($team->id);

    $dashboardResponse = $this
        ->actingAs($user->fresh())
        ->get(route('dashboard'));

    $dashboardResponse->assertOk();
});
