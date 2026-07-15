<?php

use App\Enums\DunningTerminalAction;
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

/*
|--------------------------------------------------------------------------
| Editing the schedule (IMPLEMENTATION_V2 §V2-4)
|--------------------------------------------------------------------------
|
| The read-only page becomes writable. What's saved goes to
| team_settings.dunning_config in the schema.md shape — the same place
| subscriptions:process-dunning reads from, so there's no second copy to
| drift.
*/

test('the retry schedule and terminal action can be edited', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();

    attachTeamOwner($team, $owner);
    $owner->switchTeam($team);

    $this->actingAs($owner)
        ->put(route('billing-settings.dunning.update'), [
            'retry_intervals_days' => [2, 5],
            'terminal_action' => 'pause',
            'incomplete_grace_days' => 10,
        ])
        ->assertRedirect();

    $config = DunningConfig::forTeam($team->fresh());

    expect($config->retryIntervalsDays)->toBe([2, 5])
        ->and($config->terminalAction)->toBe(DunningTerminalAction::Pause)
        ->and($config->incompleteGraceDays)->toBe(10)
        // Attempts are derived from the schedule — one per retry plus the
        // original charge — so the two can never contradict each other.
        ->and($config->maxAttempts)->toBe(3);
});

test('the saved schedule is written in the schema.md shape', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();

    attachTeamOwner($team, $owner);
    $owner->switchTeam($team);

    $this->actingAs($owner)->put(route('billing-settings.dunning.update'), [
        'retry_intervals_days' => [1, 4],
        'terminal_action' => 'leave_open',
        'incomplete_grace_days' => 3,
    ]);

    expect($team->fresh()->settings->dunning_config)->toBe([
        'max_attempts' => 3,
        'retry_intervals_days' => [1, 4],
        'terminal_action' => 'leave_open',
        'incomplete_grace_days' => 3,
    ]);
});

test('an edited schedule is what the dunning worker reads back', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();

    attachTeamOwner($team, $owner);
    $owner->switchTeam($team);

    $this->actingAs($owner)->put(route('billing-settings.dunning.update'), [
        'retry_intervals_days' => [2, 6, 9],
        'terminal_action' => 'cancel',
        'incomplete_grace_days' => 7,
    ]);

    // The interval the worker waits after each failed attempt comes straight
    // from the team's saved config, not the engine default.
    $config = DunningConfig::forTeam($team->fresh());

    expect($config->retryIntervalDaysAfterAttempt(1))->toBe(2)
        ->and($config->retryIntervalDaysAfterAttempt(2))->toBe(6)
        ->and($config->retryIntervalDaysAfterAttempt(3))->toBe(9)
        // Past the end of the schedule the last interval holds.
        ->and($config->retryIntervalDaysAfterAttempt(4))->toBe(9);
});

test('editing the schedule twice replaces it rather than appending', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();

    attachTeamOwner($team, $owner);
    $owner->switchTeam($team);

    $this->actingAs($owner)->put(route('billing-settings.dunning.update'), [
        'retry_intervals_days' => [1, 2, 3],
        'terminal_action' => 'cancel',
        'incomplete_grace_days' => 7,
    ]);

    $this->actingAs($owner)->put(route('billing-settings.dunning.update'), [
        'retry_intervals_days' => [9],
        'terminal_action' => 'cancel',
        'incomplete_grace_days' => 7,
    ]);

    expect(DunningConfig::forTeam($team->fresh())->retryIntervalsDays)->toBe([9]);
});

test('a schedule with no retries is rejected', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();

    attachTeamOwner($team, $owner);
    $owner->switchTeam($team);

    $this->actingAs($owner)
        ->put(route('billing-settings.dunning.update'), [
            'retry_intervals_days' => [],
            'terminal_action' => 'cancel',
            'incomplete_grace_days' => 7,
        ])
        ->assertSessionHasErrors('retry_intervals_days');
});

test('an unknown terminal action is rejected', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();

    attachTeamOwner($team, $owner);
    $owner->switchTeam($team);

    $this->actingAs($owner)
        ->put(route('billing-settings.dunning.update'), [
            'retry_intervals_days' => [1],
            'terminal_action' => 'delete_everything',
            'incomplete_grace_days' => 7,
        ])
        ->assertSessionHasErrors('terminal_action');
});

test('a member without invoice management cannot edit the schedule', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();

    // Support can process refunds but holds no invoices.manage — it must not
    // be able to rewrite how every subscription is dunned.
    attachTeamOwner($team, $owner);
    attachTeamMember($team, $member, 'Support');
    $member->switchTeam($team);

    $this->actingAs($member)
        ->put(route('billing-settings.dunning.update'), [
            'retry_intervals_days' => [1],
            'terminal_action' => 'cancel',
            'incomplete_grace_days' => 7,
        ])
        ->assertForbidden();

    expect($team->fresh()->settings?->dunning_config)->toBeNull();
});
