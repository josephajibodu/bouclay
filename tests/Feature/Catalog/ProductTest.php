<?php

use App\Models\Product;
use App\Models\Team;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('the products index page can be rendered', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    Product::factory()->for($team)->create(['name' => 'Pro Plan']);

    attachTeamOwner($team, $owner);
    $owner->switchTeam($team);

    $response = $this
        ->actingAs($owner)
        ->get(route('catalog.products.index'));

    $response
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('catalog/products')
            ->has('products', 1)
            ->where('products.0.name', 'Pro Plan')
            ->where('canManage', true),
        );
});

test('members without products permission cannot view the page', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();

    attachTeamOwner($team, $owner);
    attachTeamMember($team, $member, 'Finance');
    $member->switchTeam($team);

    $response = $this
        ->actingAs($member)
        ->get(route('catalog.products.index'));

    $response->assertForbidden();
});

test('a product can be created with a price in one request', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create(['default_currency' => 'NGN']);

    attachTeamOwner($team, $owner);
    $owner->switchTeam($team);

    $response = $this
        ->actingAs($owner)
        ->post(route('catalog.products.store'), [
            'name' => 'Pro Plan',
            'description' => 'For growing teams',
            'category' => 'SaaS',
            'price' => [
                'type' => 'recurring',
                'pricing_model' => 'standard',
                'unit_amount' => 15000,
                'billing_interval' => 'month',
                'billing_frequency' => 1,
            ],
        ]);

    $product = Product::query()->where('name', 'Pro Plan')->firstOrFail();

    $response->assertRedirect(route('catalog.products.show', $product));

    expect($product->prices()->count())->toBe(1);

    $price = $product->prices()->firstOrFail();
    expect($price->unit_amount)->toBe(1500000)
        ->and($price->currency)->toBe('NGN')
        ->and($price->billing_interval->value)->toBe('month');
});

test('a product can be created without a price', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();

    attachTeamOwner($team, $owner);
    $owner->switchTeam($team);

    $response = $this
        ->actingAs($owner)
        ->post(route('catalog.products.store'), [
            'name' => 'Enterprise',
        ]);

    $product = Product::query()->where('name', 'Enterprise')->firstOrFail();

    $response->assertRedirect(route('catalog.products.show', $product));
    expect($product->status->value)->toBe('active')
        ->and($product->prices()->count())->toBe(0);
});

test('a product can be archived', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $product = Product::factory()->for($team)->create();

    attachTeamOwner($team, $owner);
    $owner->switchTeam($team);

    $this
        ->actingAs($owner)
        ->patch(route('catalog.products.update', $product), ['status' => 'archived'])
        ->assertRedirect();

    expect($product->fresh()->status->value)->toBe('archived');
});

test('product metadata can be updated', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $product = Product::factory()->for($team)->create();

    attachTeamOwner($team, $owner);
    $owner->switchTeam($team);

    $this
        ->actingAs($owner)
        ->patch(route('catalog.products.update', $product), [
            'custom_data' => ['external_id' => 'acme-987'],
        ])
        ->assertRedirect();

    expect($product->fresh()->custom_data)->toBe(['external_id' => 'acme-987']);
});

test('a product website url can be set and is shown on the product page', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $product = Product::factory()->for($team)->create();

    attachTeamOwner($team, $owner);
    $owner->switchTeam($team);

    $this
        ->actingAs($owner)
        ->patch(route('catalog.products.update', $product), [
            'website_url' => 'https://acme.example.com/app',
        ])
        ->assertRedirect();

    expect($product->fresh()->website_url)->toBe('https://acme.example.com/app');

    $this
        ->actingAs($owner)
        ->get(route('catalog.products.show', $product))
        ->assertInertia(fn (Assert $page) => $page
            ->component('catalog/show')
            ->where('product.websiteUrl', 'https://acme.example.com/app'),
        );
});

test('a product website url must be a valid url', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $product = Product::factory()->for($team)->create();

    attachTeamOwner($team, $owner);
    $owner->switchTeam($team);

    $this
        ->actingAs($owner)
        ->patch(route('catalog.products.update', $product), [
            'website_url' => 'not-a-url',
        ])
        ->assertSessionHasErrors('website_url');

    expect($product->fresh()->website_url)->toBeNull();
});
