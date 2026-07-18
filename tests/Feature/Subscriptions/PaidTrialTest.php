<?php

use App\Actions\Subscriptions\AdvanceSubscriptionSchedule;
use App\Actions\Subscriptions\CreateSubscription;
use App\Enums\BillingInterval;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\PriceType;
use App\Enums\SubscriptionScheduleStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Customer;
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
| Paid trial (schedule-backed) — step-0 charge > 0 follows incomplete → active
|--------------------------------------------------------------------------
|
| schema.md §5: a paid trial is an ad hoc Subscription Schedule whose step-0
| charge is non-zero. Unlike a free trial it captures payment at signup and
| follows the normal `incomplete → active` path (paid trials are treated as
| active, not trialing); `subscriptions:advance-schedule` then re-anchors
| the item onto the regular step at the boundary.
*/

/**
 * A "Pro" plan with an intro-priced step-0 (₦1,000/mo, 1 month) followed by
 * the regular ₦5,000/mo step forever. Returns everything a signup needs.
 *
 * @return array{team: Team, customer: Customer, card: PaymentMethod, home: Price, trialPrice: Price}
 */
function paidTrialRamp(): array
{
    $owner = User::factory()->create();
    $team = Team::factory()->create(['default_currency' => 'NGN']);
    attachTeamOwner($team, $owner);
    $owner->switchTeam($team);

    $product = Product::factory()->for($team)->create(['name' => 'Pro']);
    $plan = Plan::factory()->for($team)->for($product)->create(['name' => 'Pro']);

    // The regular, steady-state price the ramp lands on.
    $home = Price::factory()->for($team)->for($product)->for($plan)->create([
        'name' => 'Pro Monthly',
        'type' => PriceType::Recurring,
        'unit_amount' => 500000,
        'currency' => 'NGN',
        'billing_interval' => BillingInterval::Month,
        'purchasable' => true,
    ]);

    // A step-only trial price — sold only as this ramp's step 0.
    $trialPrice = Price::factory()->for($team)->for($product)->for($plan)->phaseOnly()->create([
        'name' => 'Pro Trial Month',
        'type' => PriceType::Recurring,
        'unit_amount' => 100000,
        'currency' => 'NGN',
        'billing_interval' => BillingInterval::Month,
    ]);

    $customer = Customer::factory()->for($team)->create(['currency' => 'NGN']);
    $card = PaymentMethod::factory()->for($team)->for($customer)->create(['is_default' => true]);

    return compact('team', 'customer', 'card', 'home', 'trialPrice');
}

/**
 * Subscribe through an ad hoc 2-step schedule: step 0 the intro price for a
 * month, step 1 (terminal) the regular price forever.
 */
function subscribeViaSchedule(Team $team, Customer $customer, PaymentMethod $card, Price $trialPrice, Price $home): Subscription
{
    return app(CreateSubscription::class)->handle($team, [
        'customer_id' => $customer->id,
        'collection_mode' => 'automatic',
        'payment_method_id' => $card->id,
        'items' => [[
            'quantity' => 1,
            'schedule_steps' => [
                ['price_id' => $trialPrice->id, 'duration_interval' => 'month', 'duration_count' => 1],
                ['price_id' => $home->id],
            ],
        ]],
    ]);
}

it('captures the step-0 charge at signup and activates (incomplete → active), never trialing', function () {
    ['team' => $team, 'customer' => $customer, 'card' => $card, 'home' => $home, 'trialPrice' => $trialPrice] = paidTrialRamp();
    TeamProcessorConnection::factory()->for($team)->testConnected()->create();
    fakeNombaCharge();

    $subscription = subscribeViaSchedule($team, $customer, $card, $trialPrice, $home);

    // The charge settles after commit, so re-read the persisted row.
    $subscription->refresh();

    // Paid trials are active, not trialing.
    expect($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->trial_ends_at)->toBeNull();

    $item = $subscription->items()->firstOrFail();
    // price_id is now LIVE — it's step 0's price at signup, not a fixed
    // "home" price with a separate effective-charge resolver.
    expect($item->price_id)->toBe($trialPrice->id)
        ->and($item->current_schedule_step_id)->not->toBeNull();

    /** @var SubscriptionSchedule $schedule */
    $schedule = $item->schedule()->firstOrFail();
    expect($schedule->status)->toBe(SubscriptionScheduleStatus::Active)
        ->and($schedule->steps()->count())->toBe(2);

    // The day-0 invoice charged the step-0 (trial) price, not the regular one.
    $invoice = $subscription->invoices()->firstOrFail();
    $payment = $invoice->payments()->firstOrFail();

    expect($invoice->status)->toBe(InvoiceStatus::Paid)
        ->and($invoice->subtotal)->toBe(100000)
        ->and($payment->status)->toBe(PaymentStatus::Succeeded)
        ->and($payment->amount)->toBe(100000);
});

it('leaves the subscription incomplete when the step-0 charge declines', function () {
    ['team' => $team, 'customer' => $customer, 'card' => $card, 'home' => $home, 'trialPrice' => $trialPrice] = paidTrialRamp();
    TeamProcessorConnection::factory()->for($team)->testConnected()->create();
    fakeNombaCharge(approved: false);

    $subscription = subscribeViaSchedule($team, $customer, $card, $trialPrice, $home);

    // No access on a decline — the whole point of `incomplete`.
    expect($subscription->status)->toBe(SubscriptionStatus::Incomplete)
        ->and($subscription->invoices()->firstOrFail()->status)->toBe(InvoiceStatus::Open);
});

it('advances to the regular step at the boundary, bills the regular price, and collapses to flat', function () {
    ['team' => $team, 'customer' => $customer, 'card' => $card, 'home' => $home, 'trialPrice' => $trialPrice] = paidTrialRamp();
    TeamProcessorConnection::factory()->for($team)->testConnected()->create();
    fakeNombaCharge();

    $subscription = subscribeViaSchedule($team, $customer, $card, $trialPrice, $home);

    // Past the step-0 boundary, advance-schedule steps to the terminal step.
    $this->travelTo(now()->addMonthNoOverflow()->addDay());
    app(AdvanceSubscriptionSchedule::class)->handle($subscription->fresh());

    $item = $subscription->items()->firstOrFail();
    // Landed on the terminal step: price_id repoints to the regular price and
    // the schedule pointer clears — the item is now an ordinary flat item.
    expect($item->price_id)->toBe($home->id)
        ->and($item->current_schedule_step_id)->toBeNull();

    $schedule = SubscriptionSchedule::query()->where('subscription_item_id', $item->id)->firstOrFail();
    expect($schedule->status)->toBe(SubscriptionScheduleStatus::Completed)
        ->and($schedule->completed_at)->not->toBeNull();

    // Two invoices now: the ₦1,000 step-0 charge and the ₦5,000 regular step.
    $latest = $subscription->invoices()->orderByDesc('id')->firstOrFail();
    expect($subscription->invoices()->count())->toBe(2)
        ->and($latest->subtotal)->toBe(500000);
});
