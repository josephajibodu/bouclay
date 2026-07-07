<?php

use App\Models\Team;
use App\Models\User;
use App\Models\WebhookEndpoint;
use Inertia\Testing\AssertableInertia as Assert;

test('an outbound webhook endpoint can be registered and reveals its signing secret once', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();

    attachTeamOwner($team, $owner);
    $owner->switchTeam($team);

    $response = $this->actingAs($owner)
        ->post(route('developers.webhooks.endpoints.store'), [
            'url' => 'https://integrator.test/webhooks/bouclay',
        ]);

    $response->assertRedirect(route('developers.webhooks.show'));

    $endpoint = WebhookEndpoint::query()->firstOrFail();

    expect($endpoint->url)->toBe('https://integrator.test/webhooks/bouclay')
        ->and($endpoint->active)->toBeTrue()
        ->and($endpoint->public_id)->toStartWith('whe_')
        ->and($endpoint->signing_secret)->toStartWith('whsec_');
});

test('the webhooks page lists outbound endpoints and recent deliveries', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();

    attachTeamOwner($team, $owner);
    $owner->switchTeam($team);

    WebhookEndpoint::factory()->for($team)->create([
        'url' => 'https://integrator.test/hook',
    ]);

    $this->actingAs($owner)
        ->get(route('developers.webhooks.show'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('developers/webhooks')
            ->has('endpoints', 1)
            ->where('endpoints.0.url', 'https://integrator.test/hook')
            ->has('deliveries')
            ->where('canManage', true),
        );
});

test('an outbound endpoint signing secret can be rotated', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $endpoint = WebhookEndpoint::factory()->for($team)->create();
    $oldSecret = $endpoint->signing_secret;

    attachTeamOwner($team, $owner);
    $owner->switchTeam($team);

    $response = $this->actingAs($owner)
        ->post(route('developers.webhooks.endpoints.rotate-secret', $endpoint));

    $response->assertRedirect(route('developers.webhooks.show'));

    expect($endpoint->refresh()->signing_secret)->not->toBe($oldSecret)
        ->and($endpoint->signing_secret)->toStartWith('whsec_');
});

test('an outbound endpoint can be disabled and removed', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $endpoint = WebhookEndpoint::factory()->for($team)->create(['active' => true]);

    attachTeamOwner($team, $owner);
    $owner->switchTeam($team);

    $this->actingAs($owner)
        ->patch(route('developers.webhooks.endpoints.update', $endpoint), [
            'active' => false,
        ])
        ->assertRedirect(route('developers.webhooks.show'));

    expect($endpoint->refresh()->active)->toBeFalse();

    $this->actingAs($owner)
        ->delete(route('developers.webhooks.endpoints.destroy', $endpoint))
        ->assertRedirect(route('developers.webhooks.show'));

    expect(WebhookEndpoint::query()->count())->toBe(0);
});

test('members without webhooks.manage permission cannot register outbound endpoints', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();

    attachTeamOwner($team, $owner);
    attachTeamMember($team, $member, 'Finance');
    $member->switchTeam($team);

    $this->actingAs($member)
        ->post(route('developers.webhooks.endpoints.store'), [
            'url' => 'https://integrator.test/webhooks/bouclay',
        ])
        ->assertForbidden();
});
