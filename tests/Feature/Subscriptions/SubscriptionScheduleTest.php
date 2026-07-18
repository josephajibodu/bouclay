<?php

use App\Actions\Subscriptions\AdvanceSubscriptionSchedule;
use App\Actions\Subscriptions\CreateSubscription;
use App\Actions\Subscriptions\RedeemDiscount;
use App\Actions\Subscriptions\UpdateSubscriptionItem;
use App\Enums\BillingInterval;
use App\Enums\CollectionMode;
use App\Enums\DiscountDuration;
use App\Enums\DiscountType;
use App\Enums\PriceType;
use App\Enums\ScheduleEndBehavior;
use App\Enums\SubscriptionScheduleStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Customer;
use App\Models\Discount;
use App\Models\Entitlement;
use App\Models\EntitlementGrant;
use App\Models\PaymentMethod;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\SubscriptionSchedule;
use App\Models\Team;
use App\Models\TeamProcessorConnection;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| Subscription Schedules — multi-step ramps, cross-plan re-anchoring,
| discount re-validation, and lifecycle guards (schema.md §5)
|--------------------------------------------------------------------------
*/

/**
 * @return array{owner: User, team: Team, customer: Customer, card: PaymentMethod, product: Product, planA: Plan, planB: Plan, priceA: Price, priceB: Price}
 */
function scheduleFixture(): array
{
    $owner = User::factory()->create();
    $team = Team::factory()->create(['default_currency' => 'NGN']);
    attachTeamOwner($team, $owner);
    $owner->switchTeam($team);

    $product = Product::factory()->for($team)->create(['name' => 'Suite']);
    $planA = Plan::factory()->for($team)->for($product)->create(['name' => 'Starter']);
    $planB = Plan::factory()->for($team)->for($product)->create(['name' => 'Standard']);

    $priceA = Price::factory()->for($team)->for($product)->for($planA)->create([
        'type' => PriceType::Recurring,
        'unit_amount' => 100000,
        'currency' => 'NGN',
        'billing_interval' => BillingInterval::Month,
    ]);

    $priceB = Price::factory()->for($team)->for($product)->for($planB)->create([
        'type' => PriceType::Recurring,
        'unit_amount' => 500000,
        'currency' => 'NGN',
        'billing_interval' => BillingInterval::Month,
    ]);

    $customer = Customer::factory()->for($team)->create(['currency' => 'NGN']);
    $card = PaymentMethod::factory()->for($team)->for($customer)->create(['is_default' => true]);

    return compact('owner', 'team', 'customer', 'card', 'product', 'planA', 'planB', 'priceA', 'priceB');
}

it('steps through a 3-step ramp, re-anchoring price at each boundary', function () {
    ['team' => $team, 'customer' => $customer, 'card' => $card, 'product' => $product, 'planA' => $planA] = scheduleFixture();

    $mid = Price::factory()->for($team)->for($product)->for($planA)->create([
        'type' => PriceType::Recurring,
        'unit_amount' => 300000,
        'currency' => 'NGN',
        'billing_interval' => BillingInterval::Month,
    ]);
    $terminal = Price::factory()->for($team)->for($product)->for($planA)->create([
        'type' => PriceType::Recurring,
        'unit_amount' => 700000,
        'currency' => 'NGN',
        'billing_interval' => BillingInterval::Month,
    ]);
    $step0 = Price::factory()->for($team)->for($product)->for($planA)->phaseOnly()->create([
        'type' => PriceType::Recurring,
        'unit_amount' => 100000,
        'currency' => 'NGN',
        'billing_interval' => BillingInterval::Month,
    ]);

    TeamProcessorConnection::factory()->for($team)->testConnected()->create();
    fakeNombaCharge();

    $subscription = app(CreateSubscription::class)->handle($team, [
        'customer_id' => $customer->id,
        'collection_mode' => 'automatic',
        'payment_method_id' => $card->id,
        'items' => [[
            'quantity' => 1,
            'schedule_steps' => [
                ['price_id' => $step0->id, 'duration_interval' => 'month', 'duration_count' => 1],
                ['price_id' => $mid->id, 'duration_interval' => 'month', 'duration_count' => 1],
                ['price_id' => $terminal->id],
            ],
        ]],
    ]);

    expect($subscription->items()->firstOrFail()->price_id)->toBe($step0->id);

    $this->travelTo(now()->addMonthNoOverflow()->addDay());
    app(AdvanceSubscriptionSchedule::class)->handle($subscription->fresh());

    $item = $subscription->items()->firstOrFail();
    expect($item->price_id)->toBe($mid->id)
        ->and($item->current_schedule_step_id)->not->toBeNull();

    $this->travelTo(now()->addMonthNoOverflow()->addDay());
    app(AdvanceSubscriptionSchedule::class)->handle($subscription->fresh());

    $item = $subscription->items()->firstOrFail();
    expect($item->current_schedule_step_id)->toBeNull();

    $schedule = SubscriptionSchedule::query()->where('subscription_item_id', $item->id)->firstOrFail();
    expect($schedule->status)->toBe(SubscriptionScheduleStatus::Completed);
});

it('starts trialing on a free step 0 and converts at the boundary', function () {
    ['team' => $team, 'customer' => $customer, 'card' => $card, 'product' => $product, 'planA' => $planA, 'priceA' => $priceA] = scheduleFixture();

    $freeStep = Price::factory()->for($team)->for($product)->for($planA)->phaseOnly()->create([
        'type' => PriceType::Recurring,
        'unit_amount' => 0,
        'currency' => 'NGN',
        'billing_interval' => BillingInterval::Month,
    ]);

    TeamProcessorConnection::factory()->for($team)->testConnected()->create();
    fakeNombaCharge();

    $subscription = app(CreateSubscription::class)->handle($team, [
        'customer_id' => $customer->id,
        'collection_mode' => 'automatic',
        'payment_method_id' => $card->id,
        'items' => [[
            'quantity' => 1,
            'schedule_steps' => [
                ['price_id' => $freeStep->id, 'duration_interval' => 'month', 'duration_count' => 1],
                ['price_id' => $priceA->id],
            ],
        ]],
    ]);

    expect($subscription->status)->toBe(SubscriptionStatus::Trialing)
        ->and($subscription->trial_ends_at)->not->toBeNull()
        ->and($subscription->invoices()->count())->toBe(0);

    $this->travelTo(now()->addMonthNoOverflow()->addDay());
    app(AdvanceSubscriptionSchedule::class)->handle($subscription->fresh());

    $subscription->refresh();
    expect($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->trial_ends_at)->toBeNull()
        ->and($subscription->items()->firstOrFail()->price_id)->toBe($priceA->id)
        ->and($subscription->invoices()->count())->toBe(1);
});

it('re-anchors entitlements when a schedule step moves the item onto a different plan', function () {
    ['team' => $team, 'customer' => $customer, 'card' => $card, 'planA' => $planA, 'planB' => $planB, 'priceA' => $priceA, 'priceB' => $priceB] = scheduleFixture();

    $entitlementA = Entitlement::factory()->for($team)->create(['code' => 'starter_features']);
    $entitlementB = Entitlement::factory()->for($team)->create(['code' => 'standard_features']);

    EntitlementGrant::create([
        'team_id' => $team->id,
        'entitlement_id' => $entitlementA->id,
        'grantor_type' => 'plan',
        'grantor_id' => $planA->id,
    ]);
    EntitlementGrant::create([
        'team_id' => $team->id,
        'entitlement_id' => $entitlementB->id,
        'grantor_type' => 'plan',
        'grantor_id' => $planB->id,
    ]);

    TeamProcessorConnection::factory()->for($team)->testConnected()->create();
    fakeNombaCharge();

    $subscription = app(CreateSubscription::class)->handle($team, [
        'customer_id' => $customer->id,
        'collection_mode' => 'automatic',
        'payment_method_id' => $card->id,
        'items' => [[
            'quantity' => 1,
            'schedule_steps' => [
                ['price_id' => $priceA->id, 'duration_interval' => 'month', 'duration_count' => 1],
                ['price_id' => $priceB->id],
            ],
        ]],
    ]);

    $customer->refresh();
    expect($customer->entitlementCodes())->toContain('starter_features')
        ->and($customer->entitlementCodes())->not->toContain('standard_features');

    $this->travelTo(now()->addMonthNoOverflow()->addDay());
    app(AdvanceSubscriptionSchedule::class)->handle($subscription->fresh());

    $item = $subscription->items()->firstOrFail();
    expect($item->plan_id)->toBe($planB->id)
        ->and($item->product_id)->toBe($planB->product_id);

    expect($customer->entitlementCodes())->toContain('standard_features')
        ->and($customer->entitlementCodes())->not->toContain('starter_features');
});

it('drops a discount that is no longer eligible after a schedule step re-anchors the plan', function () {
    ['team' => $team, 'customer' => $customer, 'card' => $card, 'planA' => $planA, 'priceA' => $priceA, 'priceB' => $priceB] = scheduleFixture();

    $discount = Discount::factory()->create([
        'team_id' => $team->id,
        'code' => 'STARTERONLY',
        'type' => DiscountType::Percentage,
        'percentage' => '10.00',
        'amount' => null,
        'currency' => null,
        'duration' => DiscountDuration::Forever,
        'duration_in_intervals' => null,
        'eligible_plan_ids' => [$planA->id],
        'eligible_price_ids' => null,
        'active' => true,
    ]);

    TeamProcessorConnection::factory()->for($team)->testConnected()->create();
    fakeNombaCharge();

    $subscription = app(CreateSubscription::class)->handle($team, [
        'customer_id' => $customer->id,
        'collection_mode' => 'automatic',
        'payment_method_id' => $card->id,
        'discount_id' => $discount->id,
        'items' => [[
            'quantity' => 1,
            'schedule_steps' => [
                ['price_id' => $priceA->id, 'duration_interval' => 'month', 'duration_count' => 1],
                ['price_id' => $priceB->id],
            ],
        ]],
    ]);

    expect($subscription->discount_id)->toBe($discount->id);

    $this->travelTo(now()->addMonthNoOverflow()->addDay());
    app(AdvanceSubscriptionSchedule::class)->handle($subscription->fresh());

    // priceB's plan (Standard) isn't in eligible_plan_ids — the redemption
    // must be permanently ended, not just skipped for one invoice.
    $subscription->refresh();
    expect($subscription->discount_id)->toBeNull();

    $latest = $subscription->invoices()->orderByDesc('id')->firstOrFail();
    expect($latest->discount_total)->toBe(0);
});

it('rejects an ad hoc deferred item change on an item with an active schedule', function () {
    ['team' => $team, 'customer' => $customer, 'card' => $card, 'priceA' => $priceA, 'priceB' => $priceB] = scheduleFixture();

    TeamProcessorConnection::factory()->for($team)->testConnected()->create();
    fakeNombaCharge();

    $subscription = app(CreateSubscription::class)->handle($team, [
        'customer_id' => $customer->id,
        'collection_mode' => 'automatic',
        'payment_method_id' => $card->id,
        'items' => [[
            'quantity' => 1,
            'schedule_steps' => [
                ['price_id' => $priceA->id, 'duration_interval' => 'month', 'duration_count' => 1],
                ['price_id' => $priceB->id],
            ],
        ]],
    ]);

    $item = $subscription->items()->firstOrFail();

    expect(fn () => app(UpdateSubscriptionItem::class)->handle($subscription, $item, quantity: 5))
        ->toThrow(InvalidArgumentException::class, 'active Pricing Journey schedule');
});

it('cancels the subscription when a schedule reaches its terminal step with end_behavior=cancel', function () {
    ['team' => $team, 'customer' => $customer, 'card' => $card, 'priceA' => $priceA, 'priceB' => $priceB] = scheduleFixture();

    TeamProcessorConnection::factory()->for($team)->testConnected()->create();
    fakeNombaCharge();

    $subscription = app(CreateSubscription::class)->handle($team, [
        'customer_id' => $customer->id,
        'collection_mode' => 'automatic',
        'payment_method_id' => $card->id,
        'items' => [[
            'quantity' => 1,
            'end_behavior' => 'cancel',
            'schedule_steps' => [
                ['price_id' => $priceA->id, 'duration_interval' => 'month', 'duration_count' => 1],
                ['price_id' => $priceB->id],
            ],
        ]],
    ]);

    $this->travelTo(now()->addMonthNoOverflow()->addDay());
    app(AdvanceSubscriptionSchedule::class)->handle($subscription->fresh());

    $subscription->refresh();
    expect($subscription->status)->toBe(SubscriptionStatus::Canceled);

    $schedule = SubscriptionSchedule::query()->where('subscription_id', $subscription->id)->firstOrFail();
    expect($schedule->status)->toBe(SubscriptionScheduleStatus::Canceled);
});

it('finalizes a 1-step schedule immediately at creation', function () {
    ['team' => $team, 'customer' => $customer, 'card' => $card, 'priceA' => $priceA] = scheduleFixture();

    TeamProcessorConnection::factory()->for($team)->testConnected()->create();
    fakeNombaCharge();

    $subscription = app(CreateSubscription::class)->handle($team, [
        'customer_id' => $customer->id,
        'collection_mode' => 'automatic',
        'payment_method_id' => $card->id,
        'items' => [[
            'quantity' => 1,
            'schedule_steps' => [
                ['price_id' => $priceA->id],
            ],
        ]],
    ]);

    $item = $subscription->items()->firstOrFail();
    expect($item->price_id)->toBe($priceA->id)
        ->and($item->current_schedule_step_id)->toBeNull();

    $schedule = SubscriptionSchedule::query()->where('subscription_item_id', $item->id)->firstOrFail();
    expect($schedule->status)->toBe(SubscriptionScheduleStatus::Completed);
});
