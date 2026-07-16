<?php

use App\Models\Entitlement;
use App\Models\Team;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/*
|--------------------------------------------------------------------------
| Entitlements CRUD + grants editor (IMPLEMENTATION_V2 §V2-5)
|--------------------------------------------------------------------------
|
| `code` is the API contract — the string a deployed application gates on —
| so it's immutable, and the grants editor revalidates ownership rather than
| trusting the ids the browser sends back.
*/

/**
 * @return array{0: User, 1: Team}
 */
function entitlementActor(): array
{
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    attachTeamOwner($team, $owner);
    $owner->switchTeam($team);

    return [$owner, $team];
}

test('the entitlements page lists codes with their grants', function () {
    $fx = naijaStreamFixture();

    $this->actingAs($fx['owner'])
        ->get(route('catalog.entitlements.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('catalog/entitlements')
            ->has('entitlements', 2)
            ->where('entitlements.0.code', 'hd_streaming')
            ->where('entitlements.0.grants.0.grantorType', 'plan')
            ->where('entitlements.0.grants.0.grantorName', 'Premium')
            ->where('entitlements.1.code', 'sports_channels')
            ->where('entitlements.1.grants.0.grantorType', 'product')
            // The grantors the editor offers.
            ->has('grantors.plans')
            ->has('grantors.products'),
        );
});

test('an entitlement can be created', function () {
    [$owner, $team] = entitlementActor();

    $this->actingAs($owner)
        ->post(route('catalog.entitlements.store'), [
            'code' => 'premium_support',
            'name' => 'Premium Support',
            'description' => 'Priority queue.',
        ])
        ->assertRedirect();

    expect($team->entitlements()->where('code', 'premium_support')->exists())->toBeTrue();
});

test('a code must be snake_case', function (string $code) {
    [$owner] = entitlementActor();

    $this->actingAs($owner)
        ->post(route('catalog.entitlements.store'), ['code' => $code, 'name' => 'X'])
        ->assertSessionHasErrors('code');
})->with([
    'spaces' => 'hd streaming',
    'caps' => 'HdStreaming',
    'dashes' => 'hd-streaming',
    'trailing underscore' => 'hd_',
]);

test('a code must be unique within the team but not across teams', function () {
    [$owner, $team] = entitlementActor();
    $team->entitlements()->create(['code' => 'hd_streaming', 'name' => 'HD']);

    $this->actingAs($owner)
        ->post(route('catalog.entitlements.store'), ['code' => 'hd_streaming', 'name' => 'Dupe'])
        ->assertSessionHasErrors('code');

    // Another team may absolutely use the same code — codes are per-team.
    [$otherOwner, $otherTeam] = entitlementActor();

    $this->actingAs($otherOwner)
        ->post(route('catalog.entitlements.store'), ['code' => 'hd_streaming', 'name' => 'HD'])
        ->assertSessionHasNoErrors();

    expect($otherTeam->entitlements()->where('code', 'hd_streaming')->exists())->toBeTrue();
});

test('the display name is editable but the code is not', function () {
    [$owner, $team] = entitlementActor();
    $entitlement = $team->entitlements()->create(['code' => 'hd_streaming', 'name' => 'HD']);

    $this->actingAs($owner)
        ->patch(route('catalog.entitlements.update', $entitlement), [
            'name' => 'HD Streaming',
            'code' => 'something_else',
        ])
        ->assertRedirect();

    // Renaming the code would silently revoke access in every deployed
    // hasEntitlement('hd_streaming') check, so it is simply not accepted.
    expect($entitlement->refresh()->name)->toBe('HD Streaming')
        ->and($entitlement->code)->toBe('hd_streaming');
});

test('grants can be replaced wholesale', function () {
    $fx = naijaStreamFixture();
    $entitlement = $fx['hdStreaming'];

    $this->actingAs($fx['owner'])
        ->put(route('catalog.entitlements.grants', $entitlement), [
            'grants' => [
                ['grantorType' => 'plan', 'grantorId' => $fx['premium']->id],
                ['grantorType' => 'product', 'grantorId' => $fx['sportsPack']->id],
            ],
        ])
        ->assertRedirect();

    expect($entitlement->refresh()->grants)->toHaveCount(2)
        ->and($entitlement->grants->pluck('grantor_type')->sort()->values()->all())
        ->toBe(['plan', 'product']);
});

test('grants can be cleared', function () {
    $fx = naijaStreamFixture();

    $this->actingAs($fx['owner'])
        ->put(route('catalog.entitlements.grants', $fx['hdStreaming']), ['grants' => []])
        ->assertRedirect();

    expect($fx['hdStreaming']->refresh()->grants)->toHaveCount(0)
        // Nothing grants it, so nobody has it.
        ->and($fx['amina']->entitlementCodes())->not->toContain('hd_streaming');
});

test('an entitlement cannot be granted from another team’s plan', function () {
    $fx = naijaStreamFixture();
    $other = naijaStreamFixture();

    // grantorId is a raw integer from the browser — an unscoped save would
    // happily let one team grant off another team's catalog.
    $this->actingAs($fx['owner'])
        ->put(route('catalog.entitlements.grants', $fx['hdStreaming']), [
            'grants' => [['grantorType' => 'plan', 'grantorId' => $other['premium']->id]],
        ])
        ->assertNotFound();

    expect($fx['hdStreaming']->refresh()->grants->pluck('grantor_id')->all())
        ->not->toContain($other['premium']->id);
});

test('an entitlement from another team cannot be touched', function () {
    $fx = naijaStreamFixture();
    [$otherOwner] = entitlementActor();

    $this->actingAs($otherOwner)
        ->patch(route('catalog.entitlements.update', $fx['hdStreaming']), ['name' => 'Hijacked'])
        ->assertNotFound();

    $this->actingAs($otherOwner)
        ->delete(route('catalog.entitlements.destroy', $fx['hdStreaming']))
        ->assertNotFound();

    expect($fx['hdStreaming']->refresh()->name)->toBe('HD Streaming');
});

test('deleting an entitlement removes its grants and revokes access', function () {
    $fx = naijaStreamFixture();
    $entitlement = $fx['hdStreaming'];

    $this->actingAs($fx['owner'])
        ->delete(route('catalog.entitlements.destroy', $entitlement))
        ->assertRedirect();

    expect(Entitlement::find($entitlement->id))->toBeNull()
        ->and($entitlement->grants()->count())->toBe(0);
});

test('a member without manage permission cannot change entitlements', function () {
    $fx = naijaStreamFixture();
    $member = User::factory()->create();
    attachTeamMember($fx['team'], $member, 'Support');
    $member->switchTeam($fx['team']);

    $this->actingAs($member)
        ->post(route('catalog.entitlements.store'), ['code' => 'sneaky', 'name' => 'X'])
        ->assertForbidden();

    $this->actingAs($member)
        ->put(route('catalog.entitlements.grants', $fx['hdStreaming']), ['grants' => []])
        ->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| The grants editor on plan/product pages (IMPLEMENTATION_V2 §V2-5)
|--------------------------------------------------------------------------
|
| The same join, edited from the grantor's side — "what does Premium
| include?" is the question a merchant actually asks while looking at a plan.
*/

test('the product page carries what each grantor grants', function () {
    $fx = naijaStreamFixture();

    $this->actingAs($fx['owner'])
        ->get(route('catalog.products.show', $fx['naijastream']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('catalog/show')
            // Every entitlement the team can grant, for the picker.
            ->has('entitlements', 2)
            ->where('entitlements.0.code', 'hd_streaming')
            // Premium grants hd_streaming (a plan grantor).
            ->where('plans.0.entitlementIds', [$fx['hdStreaming']->id])
            // NaijaStream the product grants nothing directly.
            ->where('product.entitlementIds', [])
            ->where('permissions.canManageEntitlements', true),
        );
});

test('a plan’s entitlements can be set from the product page', function () {
    $fx = naijaStreamFixture();

    $this->actingAs($fx['owner'])
        ->put(route('catalog.plans.entitlements', [$fx['naijastream'], $fx['premium']]), [
            'entitlementIds' => [$fx['hdStreaming']->id, $fx['sportsChannels']->id],
        ])
        ->assertRedirect();

    // Premium now grants both, so a Premium subscriber gets both.
    expect($fx['hdStreaming']->refresh()->grants)->toHaveCount(1)
        ->and($fx['sportsChannels']->refresh()->grants->pluck('grantor_id')->all())
        ->toContain($fx['premium']->id);
});

test('a product’s entitlements can be set and reach its subscribers', function () {
    $fx = naijaStreamFixture();

    $this->actingAs($fx['owner'])
        ->put(route('catalog.products.entitlements', $fx['naijastream']), [
            'entitlementIds' => [$fx['sportsChannels']->id],
        ])
        ->assertRedirect();

    $grant = $fx['sportsChannels']->refresh()->grants->firstWhere('grantor_id', $fx['naijastream']->id);

    // The enforced morph map stores the stable alias, never a class FQN.
    expect($grant)->not->toBeNull()
        ->and($grant->grantor_type)->toBe('product');
});

test('clearing a grantor’s entitlements removes only its own grants', function () {
    $fx = naijaStreamFixture();

    // Sports Pack (product) grants sports_channels; Premium (plan) grants
    // hd_streaming. Clearing the product must leave the plan's grant alone.
    $this->actingAs($fx['owner'])
        ->put(route('catalog.products.entitlements', $fx['sportsPack']), ['entitlementIds' => []])
        ->assertRedirect();

    expect($fx['sportsChannels']->refresh()->grants)->toHaveCount(0)
        ->and($fx['hdStreaming']->refresh()->grants)->toHaveCount(1);
});

test('a grantor cannot be given another team’s entitlement', function () {
    $fx = naijaStreamFixture();
    $other = naijaStreamFixture();

    // The id is a raw integer from the browser.
    $this->actingAs($fx['owner'])
        ->put(route('catalog.plans.entitlements', [$fx['naijastream'], $fx['premium']]), [
            'entitlementIds' => [$other['hdStreaming']->id],
        ])
        ->assertNotFound();

    expect($other['hdStreaming']->refresh()->grants->pluck('grantor_id')->all())
        ->not->toContain($fx['premium']->id);
});

test('a plan from another team’s product cannot be edited', function () {
    $fx = naijaStreamFixture();
    $other = naijaStreamFixture();

    $this->actingAs($fx['owner'])
        ->put(route('catalog.plans.entitlements', [$other['naijastream'], $other['premium']]), [
            'entitlementIds' => [],
        ])
        ->assertNotFound();

    expect($other['hdStreaming']->refresh()->grants)->toHaveCount(1);
});

test('a member without manage permission cannot edit grants from a plan page', function () {
    $fx = naijaStreamFixture();
    $member = User::factory()->create();
    attachTeamMember($fx['team'], $member, 'Support');
    $member->switchTeam($fx['team']);

    $this->actingAs($member)
        ->put(route('catalog.plans.entitlements', [$fx['naijastream'], $fx['premium']]), [
            'entitlementIds' => [],
        ])
        ->assertForbidden();
});
