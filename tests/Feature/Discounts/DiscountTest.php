<?php

use App\Enums\DiscountDuration;
use App\Enums\DiscountType;
use App\Models\Discount;
use App\Models\DiscountRedemption;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * A team + owner with discounts.* via the Admin role.
 *
 * @return array{owner: User, team: Team}
 */
function discountFixture(): array
{
    $owner = User::factory()->create();
    $team = Team::factory()->create(['default_currency' => 'NGN']);
    attachTeamOwner($team, $owner);
    $owner->switchTeam($team);

    return ['owner' => $owner, 'team' => $team];
}

test('the discounts index renders for a viewer', function () {
    ['owner' => $owner, 'team' => $team] = discountFixture();
    Discount::factory()->for($team)->create(['code' => 'SAVE10']);

    $this->actingAs($owner)
        ->get(route('discounts.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('discounts/index')
            ->has('discounts', 1)
            ->where('discounts.0.code', 'SAVE10')
            ->where('canManage', true));
});

test('a member without the discounts permission cannot view the page', function () {
    ['team' => $team] = discountFixture();
    $member = User::factory()->create();
    attachTeamMember($team, $member, 'Finance');
    $member->switchTeam($team);

    $this->actingAs($member)
        ->get(route('discounts.index'))
        ->assertForbidden();
});

test('creates a percentage discount', function () {
    ['owner' => $owner, 'team' => $team] = discountFixture();

    $this->actingAs($owner)
        ->post(route('discounts.store'), [
            'code' => 'WELCOME20',
            'type' => 'percentage',
            'percentage' => 20,
            'duration' => 'repeating',
            'duration_in_intervals' => 3,
        ])
        ->assertRedirect();

    $discount = Discount::query()->firstOrFail();
    expect($discount->code)->toBe('WELCOME20')
        ->and($discount->type)->toBe(DiscountType::Percentage)
        ->and($discount->percentage)->toBe('20.00')
        ->and($discount->amount)->toBeNull()
        ->and($discount->duration)->toBe(DiscountDuration::Repeating)
        ->and($discount->duration_in_intervals)->toBe(3);
});

test('creates a flat discount converting the amount to minor units', function () {
    ['owner' => $owner, 'team' => $team] = discountFixture();

    $this->actingAs($owner)
        ->post(route('discounts.store'), [
            'type' => 'flat',
            'amount' => 1000, // ₦1,000 major units
            'currency' => 'NGN',
            'duration' => 'once',
        ])
        ->assertRedirect();

    $discount = Discount::query()->firstOrFail();
    expect($discount->type)->toBe(DiscountType::Flat)
        ->and($discount->amount)->toBe(100000)
        ->and($discount->currency)->toBe('NGN')
        ->and($discount->percentage)->toBeNull();
});

test('scopes eligible plan ids to plans the team owns', function () {
    ['owner' => $owner, 'team' => $team] = discountFixture();
    $product = Product::factory()->for($team)->create();
    $ownPlan = Plan::factory()->for($team)->for($product)->create();

    $otherTeam = Team::factory()->create();
    $foreignPlan = Plan::factory()->for($otherTeam)->create();

    $this->actingAs($owner)
        ->post(route('discounts.store'), [
            'type' => 'percentage',
            'percentage' => 10,
            'duration' => 'forever',
            'eligible_plan_ids' => [$ownPlan->id, $foreignPlan->id],
        ])
        ->assertRedirect();

    // The foreign plan id is dropped — a spoofed id can't widen the promo.
    expect(Discount::query()->firstOrFail()->eligible_plan_ids)->toBe([$ownPlan->id]);
});

test('rejects a percentage discount without a percentage', function () {
    ['owner' => $owner] = discountFixture();

    $this->actingAs($owner)
        ->post(route('discounts.store'), [
            'type' => 'percentage',
            'duration' => 'once',
        ])
        ->assertSessionHasErrors('percentage');
});

test('updates a discount', function () {
    ['owner' => $owner, 'team' => $team] = discountFixture();
    $discount = Discount::factory()->for($team)->create([
        'type' => DiscountType::Percentage,
        'percentage' => '10.00',
        'duration' => DiscountDuration::Once,
        'active' => true,
    ]);

    $this->actingAs($owner)
        ->patch(route('discounts.update', $discount), [
            'type' => 'percentage',
            'percentage' => 25,
            'duration' => 'once',
            'active' => false,
        ])
        ->assertRedirect();

    expect($discount->fresh()->percentage)->toBe('25.00')
        ->and($discount->fresh()->active)->toBeFalse();
});

test('deletes an unused discount', function () {
    ['owner' => $owner, 'team' => $team] = discountFixture();
    $discount = Discount::factory()->for($team)->create();

    $this->actingAs($owner)
        ->delete(route('discounts.destroy', $discount))
        ->assertRedirect();

    expect(Discount::query()->count())->toBe(0);
});

test('deactivates a redeemed discount instead of deleting it', function () {
    ['owner' => $owner, 'team' => $team] = discountFixture();
    $discount = Discount::factory()->for($team)->create(['active' => true]);
    $subscription = Subscription::factory()->for($team)->create();
    DiscountRedemption::factory()->for($discount)->for($subscription)->create();

    $this->actingAs($owner)
        ->delete(route('discounts.destroy', $discount))
        ->assertRedirect();

    // History survives — the row is deactivated, not deleted.
    expect(Discount::query()->count())->toBe(1)
        ->and($discount->fresh()->active)->toBeFalse();
});

test('rejects a duplicate code within the team', function () {
    ['owner' => $owner, 'team' => $team] = discountFixture();
    Discount::factory()->for($team)->create(['code' => 'DUPE']);

    $this->actingAs($owner)
        ->post(route('discounts.store'), [
            'code' => 'DUPE',
            'type' => 'percentage',
            'percentage' => 10,
            'duration' => 'once',
        ])
        ->assertSessionHasErrors('code');
});
