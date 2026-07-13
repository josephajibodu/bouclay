<?php

use App\Enums\PlanStatus;
use App\Models\Plan;
use App\Models\Product;

test('plans can be created, listed, and archived via api', function () {
    ['token' => $token, 'team' => $team] = apiAuthFixture();
    $product = Product::factory()->for($team)->create();

    $create = $this->postJson("/api/v1/products/{$product->public_id}/plans", [
        'name' => 'Premium',
        'code' => 'premium',
    ], apiHeaders($token, 'plan-create-1'));

    $create->assertCreated()
        ->assertJsonPath('data.name', 'Premium')
        ->assertJsonPath('data.status', 'active');

    $planPublicId = $create->json('data.id');

    $this->getJson('/api/v1/plans?productId='.$product->public_id, apiHeaders($token))
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->postJson("/api/v1/plans/{$planPublicId}/archive", [], apiHeaders($token, 'plan-archive-1'))
        ->assertOk()
        ->assertJsonPath('data.status', 'archived');
});

test('a recurring price created via api requires its plan to match the product', function () {
    ['token' => $token, 'team' => $team] = apiAuthFixture();
    $product = Product::factory()->for($team)->create();
    $otherProduct = Product::factory()->for($team)->create();
    $foreignPlan = Plan::factory()->for($team)->for($otherProduct)->create(['status' => PlanStatus::Active]);

    $this->postJson("/api/v1/products/{$product->public_id}/prices", [
        'planId' => $foreignPlan->public_id,
        'type' => 'recurring',
        'pricingModel' => 'standard',
        'unitAmount' => 5000,
        'currency' => 'NGN',
        'billingInterval' => 'month',
    ], apiHeaders($token, 'price-foreign-plan-1'))
        ->assertUnprocessable()
        ->assertJsonFragment(['field' => 'planId']);
});

test('a price created under a plan carries the plan id', function () {
    ['token' => $token, 'team' => $team] = apiAuthFixture();
    $product = Product::factory()->for($team)->create();
    $plan = Plan::factory()->for($team)->for($product)->create(['status' => PlanStatus::Active]);

    $this->postJson("/api/v1/products/{$product->public_id}/prices", [
        'planId' => $plan->public_id,
        'type' => 'recurring',
        'pricingModel' => 'standard',
        'unitAmount' => 5000,
        'currency' => 'NGN',
        'billingInterval' => 'month',
    ], apiHeaders($token, 'price-with-plan-1'))
        ->assertCreated()
        ->assertJsonPath('data.planId', $plan->public_id);
});
