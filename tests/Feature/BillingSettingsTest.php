<?php

use App\Models\Team;
use App\Models\User;
use App\Support\DunningConfig;
use Inertia\Testing\AssertableInertia as Assert;

test('the billing settings page shows the team dunning defaults', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();

    attachTeamOwner($team, $owner);
    $owner->switchTeam($team);

    $response = $this
        ->actingAs($owner)
        ->get(route('billing-settings.show'));

    $response
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('billing-settings/show')
            ->where('dunning.maxAttempts', DunningConfig::defaults()->maxAttempts)
            ->where('dunning.retryIntervalsDays', DunningConfig::defaults()->retryIntervalsDays)
            ->where('dunning.terminalAction', DunningConfig::defaults()->terminalAction->value)
            ->where('dunning.incompleteGraceDays', DunningConfig::defaults()->incompleteGraceDays),
        );
});

test('the billing settings page reflects a team override of the dunning config', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();

    attachTeamOwner($team, $owner);
    $owner->switchTeam($team);

    $team->settings()->create([
        'dunning_config' => [
            'max_attempts' => 2,
            'retry_intervals_days' => [5, 10],
            'terminal_action' => 'pause',
            'incomplete_grace_days' => 3,
        ],
    ]);

    $response = $this
        ->actingAs($owner)
        ->get(route('billing-settings.show'));

    $response
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('billing-settings/show')
            ->where('dunning.maxAttempts', 2)
            ->where('dunning.retryIntervalsDays', [5, 10])
            ->where('dunning.terminalAction', 'pause')
            ->where('dunning.incompleteGraceDays', 3),
        );
});

test('members without invoices permission cannot view the billing settings page', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();

    attachTeamOwner($team, $owner);
    attachTeamMember($team, $member, 'Developer');
    $member->switchTeam($team);

    $response = $this
        ->actingAs($member)
        ->get(route('billing-settings.show'));

    $response->assertForbidden();
});
