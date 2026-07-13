<?php

use App\Actions\Subscriptions\CreateSubscription;
use App\Actions\Subscriptions\UpdateSubscriptionItem;
use App\Enums\BillingInterval;
use App\Enums\PriceType;
use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Subscription;
use App\Models\SubscriptionItem;

/*
|--------------------------------------------------------------------------
| ADV-05 — Two recurring items on different billing intervals (forbidden)
|--------------------------------------------------------------------------
|
| BILLING_SIMULATIONS.md ADV-05 / GAP-5 (locked): current_period_start/end
| live on the subscription — one renewal clock per subscription. Mixed
| cadence is rejected at create and item-update; multi-cadence customers
| hold multiple subscriptions (the `type` named slot). Promoted in V2-2.
*/

/**
 * The NaijaStream fixture plus a second Premium price that bills annually —
 * same plan, different cadence, so pairing the two on one subscription is the
 * forbidden mixed-cadence case.
 *
 * @return array{fx: array<string, mixed>, annual: Price, annualPlan: Plan}
 */
function mixedCadenceFixture(): array
{
    $fx = naijaStreamFixture();
    $team = $fx['team'];

    // A distinct plan billing annually (a second cadence on the same customer).
    $annualPlan = Plan::factory()->for($team)->for($fx['naijastream'])->create(['name' => 'Premium Annual']);

    $annual = Price::factory()->for($team)->for($fx['naijastream'])->for($annualPlan)->create([
        'name' => 'Premium Annual',
        'type' => PriceType::Recurring,
        'unit_amount' => 5000000,
        'currency' => 'NGN',
        'billing_interval' => BillingInterval::Year,
        'purchasable' => true,
    ]);

    return ['fx' => $fx, 'annual' => $annual, 'annualPlan' => $annualPlan];
}

it('rejects creating a subscription mixing monthly and annual items', function () {
    ['fx' => $fx, 'annual' => $annual] = mixedCadenceFixture();

    expect(fn () => app(CreateSubscription::class)->handle($fx['team'], [
        'customer_id' => $fx['amina']->id,
        'collection_mode' => 'manual',
        'items' => [
            ['price_id' => $fx['price_sports_m']->id, 'quantity' => 1],
            ['price_id' => $annual->id, 'quantity' => 1],
        ],
    ]))->toThrow(InvalidArgumentException::class, 'one billing cadence');
});

it('rejects an item update that would introduce a second cadence', function () {
    ['fx' => $fx, 'annual' => $annual] = mixedCadenceFixture();
    $team = $fx['team'];

    // An active monthly subscription with a monthly add-on already on it.
    $subscription = Subscription::factory()->for($team)->for($fx['amina'])->create([
        'status' => SubscriptionStatus::Active,
        'currency' => 'NGN',
    ]);

    $base = SubscriptionItem::factory()->for($subscription)->create([
        'price_id' => $fx['price_prem_m']->id,
        'plan_id' => $fx['premium']->id,
        'product_id' => $fx['naijastream']->id,
    ]);

    $addon = SubscriptionItem::factory()->for($subscription)->create([
        'price_id' => $fx['price_sports_m']->id,
        'plan_id' => $fx['sportsPackPlan']->id,
        'product_id' => $fx['sportsPack']->id,
    ]);

    // Swapping the add-on onto an annual price breaks the single clock.
    expect(fn () => app(UpdateSubscriptionItem::class)->handle(
        subscription: $subscription,
        item: $addon,
        priceId: $annual->id,
    ))->toThrow(InvalidArgumentException::class, 'one billing cadence');

    // The item is untouched after the rejected swap.
    expect($addon->fresh()->price_id)->toBe($fx['price_sports_m']->id);
});

it('supports monthly and annual charges as two subscriptions on one customer', function () {
    ['fx' => $fx, 'annual' => $annual] = mixedCadenceFixture();
    $team = $fx['team'];

    $monthly = app(CreateSubscription::class)->handle($team, [
        'customer_id' => $fx['amina']->id,
        'collection_mode' => 'manual',
        'items' => [['price_id' => $fx['price_sports_m']->id, 'quantity' => 1]],
    ]);

    $yearly = app(CreateSubscription::class)->handle($team, [
        'customer_id' => $fx['amina']->id,
        'collection_mode' => 'manual',
        'items' => [['price_id' => $annual->id, 'quantity' => 1]],
    ]);

    // Two subscriptions, each with its own clock — the sanctioned path.
    expect($monthly->id)->not->toBe($yearly->id)
        ->and($team->subscriptions()->where('customer_id', $fx['amina']->id)->count())->toBe(2)
        ->and($monthly->current_period_end->toDateString())->toBe(now()->addMonth()->toDateString())
        ->and($yearly->current_period_end->toDateString())->toBe(now()->addYear()->toDateString());
});
