<?php

use App\Actions\Subscriptions\AdvanceSubscriptionPhases;
use App\Actions\Subscriptions\CreateSubscription;
use App\Enums\BillingInterval;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\PriceType;
use App\Enums\SubscriptionStatus;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Plan;
use App\Models\Price;
use App\Models\PricePhase;
use App\Models\Product;
use App\Models\Team;
use App\Models\TeamProcessorConnection;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| Paid trial (phased) — phase-0 charge > 0 follows incomplete → active
|--------------------------------------------------------------------------
|
| schema.md §5 / IMPLEMENTATION_V2 §V2-2: a paid trial is a `price_phases`
| ramp whose phase-0 charge is non-zero. Unlike a free trial it captures
| payment at signup and follows the normal `incomplete → active` path (paid
| trials are treated as active, not trialing); `subscriptions:advance-phases`
| then swaps the effective price to the regular phase at the boundary.
*/

/**
 * A "Pro Monthly" plan whose price ramps: phase 0 charges a ₦1,000 trial
 * price for a month, phase 1 the regular ₦5,000. Returns everything a signup
 * needs.
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

    // The nominal "home" price the customer picks — the regular ₦5,000/mo.
    $home = Price::factory()->for($team)->for($product)->for($plan)->create([
        'name' => 'Pro Monthly',
        'type' => PriceType::Recurring,
        'unit_amount' => 500000,
        'currency' => 'NGN',
        'billing_interval' => BillingInterval::Month,
        'purchasable' => true,
    ]);

    // A phase-only trial price — sold only as phase 0's charge target.
    $trialPrice = Price::factory()->for($team)->for($product)->for($plan)->phaseOnly()->create([
        'name' => 'Pro Trial Month',
        'type' => PriceType::Recurring,
        'unit_amount' => 100000,
        'currency' => 'NGN',
        'billing_interval' => BillingInterval::Month,
    ]);

    PricePhase::factory()->create([
        'price_id' => $home->id,
        'sequence' => 0,
        'charge_price_id' => $trialPrice->id,
        'duration_interval' => BillingInterval::Month,
        'duration_count' => 1,
    ]);

    PricePhase::factory()->create([
        'price_id' => $home->id,
        'sequence' => 1,
        'charge_price_id' => $home->id,
        'duration_interval' => BillingInterval::Month,
        'duration_count' => 1,
    ]);

    $customer = Customer::factory()->for($team)->create(['currency' => 'NGN']);
    $card = PaymentMethod::factory()->for($team)->for($customer)->create(['is_default' => true]);

    return compact('team', 'customer', 'card', 'home', 'trialPrice');
}

it('captures the phase-0 charge at signup and activates (incomplete → active), never trialing', function () {
    ['team' => $team, 'customer' => $customer, 'card' => $card, 'home' => $home] = paidTrialRamp();
    TeamProcessorConnection::factory()->for($team)->testConnected()->create();
    fakeNombaCharge();

    $subscription = app(CreateSubscription::class)->handle($team, [
        'customer_id' => $customer->id,
        'collection_mode' => 'automatic',
        'payment_method_id' => $card->id,
        'items' => [['price_id' => $home->id, 'quantity' => 1]],
    ]);

    // The charge settles after commit, so re-read the persisted row.
    $subscription->refresh();

    // Paid trials are active, not trialing.
    expect($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->trial_ends_at)->toBeNull();

    $item = $subscription->items()->firstOrFail();
    expect($item->current_phase_sequence)->toBe(0)
        // The nominal price stays the home price; the phase drives the charge.
        ->and($item->price_id)->toBe($home->id);

    // The day-0 invoice charged the phase-0 (trial) price, not the regular one.
    $invoice = $subscription->invoices()->firstOrFail();
    $payment = $invoice->payments()->firstOrFail();

    expect($invoice->status)->toBe(InvoiceStatus::Paid)
        ->and($invoice->subtotal)->toBe(100000)
        ->and($payment->status)->toBe(PaymentStatus::Succeeded)
        ->and($payment->amount)->toBe(100000);
});

it('leaves the subscription incomplete when the phase-0 charge declines', function () {
    ['team' => $team, 'customer' => $customer, 'card' => $card, 'home' => $home] = paidTrialRamp();
    TeamProcessorConnection::factory()->for($team)->testConnected()->create();
    fakeNombaCharge(approved: false);

    $subscription = app(CreateSubscription::class)->handle($team, [
        'customer_id' => $customer->id,
        'collection_mode' => 'automatic',
        'payment_method_id' => $card->id,
        'items' => [['price_id' => $home->id, 'quantity' => 1]],
    ]);

    // No access on a decline — the whole point of `incomplete`.
    expect($subscription->status)->toBe(SubscriptionStatus::Incomplete)
        ->and($subscription->invoices()->firstOrFail()->status)->toBe(InvoiceStatus::Open);
});

it('advances to the regular phase at the boundary and bills the regular price', function () {
    ['team' => $team, 'customer' => $customer, 'card' => $card, 'home' => $home] = paidTrialRamp();
    TeamProcessorConnection::factory()->for($team)->testConnected()->create();
    fakeNombaCharge();

    $subscription = app(CreateSubscription::class)->handle($team, [
        'customer_id' => $customer->id,
        'collection_mode' => 'automatic',
        'payment_method_id' => $card->id,
        'items' => [['price_id' => $home->id, 'quantity' => 1]],
    ]);

    // Past the phase-0 boundary, advance-phases steps to phase 1.
    $this->travelTo(now()->addMonthNoOverflow()->addDay());
    app(AdvanceSubscriptionPhases::class)->handle($subscription->fresh());

    $item = $subscription->items()->firstOrFail();
    expect($item->current_phase_sequence)->toBe(1);

    // Two invoices now: the ₦1,000 phase-0 charge and the ₦5,000 regular phase.
    $latest = $subscription->invoices()->orderByDesc('id')->firstOrFail();
    expect($subscription->invoices()->count())->toBe(2)
        ->and($latest->subtotal)->toBe(500000);
});
