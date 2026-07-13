<?php

use App\Actions\Subscriptions\RedeemDiscount;
use App\Actions\Subscriptions\RenewSubscription;
use App\Enums\DiscountDuration;
use App\Enums\DiscountType;
use App\Models\Discount;
use App\Models\DiscountRedemption;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| ADV-08 — Apply / remove a discount mid-subscription
|--------------------------------------------------------------------------
|
| BILLING_SIMULATIONS.md ADV-08: the single discount_id FK means no
| stacking; redemption state (remaining_intervals) is what survives a
| swap — re-hits GAP-1. Promoted in V2-3. Uses seatSubscription() (Pest.php).
*/

/**
 * A team-wide percentage discount eligible for the seat plan.
 */
function seatDiscount(array $overrides = []): Discount
{
    return Discount::factory()->create(array_merge([
        'code' => 'SEAT10',
        'type' => DiscountType::Percentage,
        'percentage' => '10.00',
        'amount' => null,
        'duration' => DiscountDuration::Forever,
        'duration_in_intervals' => null,
        'eligible_plan_ids' => null,
        'eligible_price_ids' => null,
        'active' => true,
    ], $overrides));
}

it('applies a discount to an already-active subscription from the next cycle', function () {
    ['fx' => $fx, 'subscription' => $subscription] = seatSubscription(10);
    fakeNombaCharge();
    $discount = seatDiscount(['team_id' => $fx['team']->id]);

    app(RedeemDiscount::class)->handle($subscription->fresh(), $discount);

    // The next renewal carries the line-level discount: 10 × 100000 = 1000000,
    // less 10% = 900000.
    $this->travelTo(Carbon::instance($subscription->current_period_end)->addDay());
    app(RenewSubscription::class)->handle($subscription->fresh());

    $renewal = $subscription->invoices()->firstOrFail();

    expect($renewal->subtotal)->toBe(1000000)
        ->and($renewal->discount_total)->toBe(100000)
        ->and($renewal->total)->toBe(900000);
});

it('removes a discount mid-subscription without touching issued invoices', function () {
    ['fx' => $fx, 'subscription' => $subscription] = seatSubscription(10);
    fakeNombaCharge();
    $discount = seatDiscount(['team_id' => $fx['team']->id]);

    app(RedeemDiscount::class)->handle($subscription->fresh(), $discount);

    // First renewal discounted.
    $this->travelTo(Carbon::instance($subscription->current_period_end)->addDay());
    app(RenewSubscription::class)->handle($subscription->fresh());
    $firstRenewal = $subscription->invoices()->orderBy('id')->firstOrFail();

    // Remove the discount, then the next renewal is undiscounted…
    app(RedeemDiscount::class)->remove($subscription->fresh());
    $this->travelTo(Carbon::instance($subscription->fresh()->current_period_end)->addDay());
    app(RenewSubscription::class)->handle($subscription->fresh());
    $secondRenewal = $subscription->invoices()->orderByDesc('id')->firstOrFail();

    // …while the already-issued invoice keeps its discount untouched.
    expect($firstRenewal->fresh()->discount_total)->toBe(100000)
        ->and($secondRenewal->discount_total)->toBe(0)
        ->and($secondRenewal->total)->toBe(1000000);
});

it('does not stack discounts — assigning a new one replaces the old', function () {
    ['fx' => $fx, 'subscription' => $subscription] = seatSubscription(10);
    $repeating = seatDiscount(['team_id' => $fx['team']->id, 'code' => 'FIRST', 'duration' => DiscountDuration::Repeating, 'duration_in_intervals' => 2]);
    $forever = seatDiscount(['team_id' => $fx['team']->id, 'code' => 'SECOND']);

    app(RedeemDiscount::class)->handle($subscription->fresh(), $repeating);
    app(RedeemDiscount::class)->handle($subscription->fresh(), $forever);

    // One discount_id — the latest wins; the new redemption has its own budget.
    expect($subscription->fresh()->discount_id)->toBe($forever->id)
        ->and(DiscountRedemption::query()->count())->toBe(2)
        ->and($subscription->fresh()->activeDiscountRedemption()->discount_id)->toBe($forever->id);
});

it('honors eligible_price_ids as the complete eligibility list when set', function () {
    ['fx' => $fx, 'subscription' => $subscription] = seatSubscription(10);

    // eligible_price_ids names a different price and eligible_plan_ids names
    // the seat plan — the price list wins outright, so the seat sub does NOT
    // qualify (the two are never combined).
    $priceScoped = seatDiscount([
        'team_id' => $fx['team']->id,
        'eligible_price_ids' => [$fx['price_prem_m']->id],
        'eligible_plan_ids' => [$fx['teamPlan']->id],
    ]);

    expect(fn () => app(RedeemDiscount::class)->handle($subscription->fresh(), $priceScoped))
        ->toThrow(InvalidArgumentException::class);

    // Naming the seat price makes it eligible.
    $matching = seatDiscount([
        'team_id' => $fx['team']->id,
        'code' => 'SEATONLY',
        'eligible_price_ids' => [$fx['price_seat_m']->id],
    ]);

    app(RedeemDiscount::class)->handle($subscription->fresh(), $matching);
    expect($subscription->fresh()->discount_id)->toBe($matching->id);
});

it('enforces max_redemptions across customers', function () {
    ['fx' => $fx, 'subscription' => $subscription] = seatSubscription(10);

    // Already at its cap.
    $capped = seatDiscount(['team_id' => $fx['team']->id, 'max_redemptions' => 1, 'times_redeemed' => 1]);

    expect(fn () => app(RedeemDiscount::class)->handle($subscription->fresh(), $capped))
        ->toThrow(InvalidArgumentException::class);
});
