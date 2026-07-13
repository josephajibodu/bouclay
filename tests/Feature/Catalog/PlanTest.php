<?php

use App\Enums\PlanStatus;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Team;
use App\Models\User;

function planOwner(): array
{
    $owner = User::factory()->create();
    $team = Team::factory()->create(['default_currency' => 'NGN']);
    $product = Product::factory()->for($team)->create();

    attachTeamOwner($team, $owner);
    $owner->switchTeam($team);

    return [$owner, $team, $product];
}

test('a plan can be added to a product', function () {
    [$owner, , $product] = planOwner();

    $this->actingAs($owner)
        ->post(route('catalog.plans.store', $product), [
            'name' => 'Premium',
            'code' => 'premium',
        ])
        ->assertRedirect();

    $plan = $product->plans()->firstOrFail();
    expect($plan->name)->toBe('Premium')
        ->and($plan->code)->toBe('premium')
        ->and($plan->status)->toBe(PlanStatus::Active);
});

test('a plan can be moved to draft and archived', function () {
    [$owner, $team, $product] = planOwner();
    $plan = Plan::factory()->for($team)->for($product)->create(['status' => PlanStatus::Active]);

    $this->actingAs($owner)
        ->patch(route('catalog.plans.update', [$product, $plan]), ['status' => 'archived'])
        ->assertRedirect();

    expect($plan->refresh()->status)->toBe(PlanStatus::Archived);
});

test('a plan from another team is not reachable', function () {
    [$owner, , $product] = planOwner();
    $otherTeam = Team::factory()->create();
    $otherProduct = Product::factory()->for($otherTeam)->create();
    $foreignPlan = Plan::factory()->for($otherTeam)->for($otherProduct)->create();

    $this->actingAs($owner)
        ->patch(route('catalog.plans.update', [$product, $foreignPlan]), ['name' => 'Hijacked'])
        ->assertNotFound();
});
