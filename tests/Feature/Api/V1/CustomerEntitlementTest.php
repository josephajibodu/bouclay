<?php

use App\Enums\ApiKeyKind;
use App\Enums\ApiKeyMode;
use App\Enums\SubscriptionItemKind;
use App\Enums\SubscriptionItemStatus;
use App\Enums\SubscriptionStatus;
use App\Models\ApiKey;
use App\Models\Customer;
use App\Models\Subscription;

/*
|--------------------------------------------------------------------------
| GET /api/v1/customers/{id}/entitlements (IMPLEMENTATION_V2 §V2-5)
|--------------------------------------------------------------------------
|
| The endpoint an integrator gates a feature on, so their access logic never
| reaches into invoices, payments, or dunning state.
*/

/**
 * Amina, subscribed to Premium (hd_streaming) + Sports Pack (sports_channels),
 * with an API key for her team.
 *
 * @param  array<string, mixed>  $attributes
 * @return array{token: string, customer: Customer, fx: array<string, mixed>, subscription: Subscription}
 */
function entitledApiFixture(array $attributes = []): array
{
    $fx = naijaStreamFixture();
    $team = $fx['team'];

    $generated = ApiKey::generate(ApiKeyMode::Test, ApiKeyKind::Secret);
    ApiKey::factory()->create([
        'team_id' => $team->id,
        'created_by' => $fx['owner']->id,
        'mode' => ApiKeyMode::Test,
        'kind' => ApiKeyKind::Secret,
        'hashed_secret' => $generated['hashedSecret'],
        'last_four' => $generated['lastFour'],
    ]);

    $subscription = Subscription::factory()->for($team)->for($fx['amina'])->create([
        'status' => SubscriptionStatus::Active,
        'currency' => 'NGN',
        'current_period_start' => now(),
        'current_period_end' => now()->addMonth(),
        ...$attributes,
    ]);

    foreach ([
        [$fx['price_prem_m'], $fx['premium'], $fx['naijastream'], SubscriptionItemKind::Plan],
        [$fx['price_sports_m'], $fx['sportsPackPlan'], $fx['sportsPack'], SubscriptionItemKind::Addon],
    ] as [$price, $plan, $product, $kind]) {
        $subscription->items()->create([
            'price_id' => $price->id,
            'plan_id' => $plan->id,
            'product_id' => $product->id,
            'kind' => $kind,
            'quantity' => 1,
            'status' => SubscriptionItemStatus::Active,
        ]);
    }

    return [
        'token' => $generated['key'],
        'customer' => $fx['amina'],
        'fx' => $fx,
        'subscription' => $subscription,
    ];
}

test('a customer’s entitlements can be listed via the api', function () {
    ['token' => $token, 'customer' => $customer] = entitledApiFixture();

    $this->getJson("/api/v1/customers/{$customer->public_id}/entitlements", apiHeaders($token))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.code', 'hd_streaming')
        ->assertJsonPath('data.0.name', 'HD Streaming')
        ->assertJsonPath('data.1.code', 'sports_channels');
});

test('the entitlements list is empty once access ends', function () {
    ['token' => $token, 'customer' => $customer] = entitledApiFixture([
        'status' => SubscriptionStatus::Canceled,
        'ends_at' => now()->subDay(),
    ]);

    $this->getJson("/api/v1/customers/{$customer->public_id}/entitlements", apiHeaders($token))
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('a single entitlement can be checked by code', function () {
    ['token' => $token, 'customer' => $customer] = entitledApiFixture();

    $this->getJson("/api/v1/customers/{$customer->public_id}/entitlements/hd_streaming", apiHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.code', 'hd_streaming')
        ->assertJsonPath('data.granted', true)
        ->assertJsonPath('data.entitlement.name', 'HD Streaming');
});

test('an entitlement the customer does not hold answers no, not 404', function () {
    ['token' => $token, 'customer' => $customer] = entitledApiFixture();

    // "No" is a legitimate answer to an access check. A 404 here would be
    // indistinguishable from an unknown customer, and would push integrators
    // into treating errors as denials.
    $this->getJson("/api/v1/customers/{$customer->public_id}/entitlements/premium_support", apiHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.code', 'premium_support')
        ->assertJsonPath('data.granted', false)
        ->assertJsonPath('data.entitlement', null);
});

test('entitlements are not readable across teams', function () {
    ['customer' => $customer] = entitledApiFixture();

    // A different team's key must not resolve this customer at all.
    ['token' => $otherToken] = apiAuthFixture();

    $this->getJson("/api/v1/customers/{$customer->public_id}/entitlements", apiHeaders($otherToken))
        ->assertNotFound();
});

test('the entitlements endpoint requires authentication', function () {
    ['customer' => $customer] = entitledApiFixture();

    $this->getJson("/api/v1/customers/{$customer->public_id}/entitlements")
        ->assertUnauthorized();
});
