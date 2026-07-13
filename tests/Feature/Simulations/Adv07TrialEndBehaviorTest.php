<?php

use App\Actions\Invoicing\CollectInvoice;
use App\Actions\Subscriptions\AdvanceSubscriptionPhases;
use App\Actions\Subscriptions\CreateSubscription;
use App\Enums\BillingInterval;
use App\Enums\InvoiceStatus;
use App\Enums\PriceType;
use App\Enums\SubscriptionStatus;
use App\Enums\TrialUnit;
use App\Models\PaymentMethod;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Subscription;
use App\Models\TeamProcessorConnection;
use Illuminate\Support\Facades\Mail;

/*
|--------------------------------------------------------------------------
| ADV-07 — Trial expires with no card, per trial_end_behavior
|--------------------------------------------------------------------------
|
| BILLING_SIMULATIONS.md ADV-07: the trickiest state path — each
| trial_end_behavior branch when the clock runs out with no payment method,
| including the late-pay → activate path. AdvanceSubscriptionPhases owns the
| fork (schema.md §5 "missing_payment_method"). Promoted in V2-2.
*/

/**
 * A card-less free-trial subscription with the given trial_end_behavior — the
 * ADV-07 starting state (a trial that doesn't require payment info, so it can
 * be created without a card). Returns the fixture + subscription.
 *
 * @return array{fx: array<string, mixed>, subscription: Subscription, price: Price}
 */
function trialingNoCard(string $behavior): array
{
    $fx = naijaStreamFixture();
    $team = $fx['team'];

    $plan = Plan::factory()->for($team)->for($fx['naijastream'])->create(['name' => 'Basic']);

    // A free trial that does NOT require a card up front.
    $price = Price::factory()->for($team)->for($fx['naijastream'])->for($plan)
        ->withTrial(7, TrialUnit::Day, false)
        ->create([
            'name' => 'Basic Monthly',
            'type' => PriceType::Recurring,
            'unit_amount' => 500000,
            'currency' => 'NGN',
            'billing_interval' => BillingInterval::Month,
            'purchasable' => true,
        ]);

    $subscription = app(CreateSubscription::class)->handle($team, [
        'customer_id' => $fx['amina']->id,
        'collection_mode' => 'automatic',
        'trial_end_behavior' => $behavior,
        'items' => [['price_id' => $price->id, 'quantity' => 1]],
    ]);

    return ['fx' => $fx, 'subscription' => $subscription, 'price' => $price];
}

it('cancels the subscription at trial end when trial_end_behavior is cancel', function () {
    ['subscription' => $subscription] = trialingNoCard('cancel');

    expect($subscription->status)->toBe(SubscriptionStatus::Trialing);

    $this->travelTo(now()->addDays(8));
    app(AdvanceSubscriptionPhases::class)->handle($subscription->fresh());

    $subscription->refresh();

    // trialing → canceled; no invoice was ever created.
    expect($subscription->status)->toBe(SubscriptionStatus::Canceled)
        ->and($subscription->canceled_at)->not->toBeNull()
        ->and($subscription->invoices()->count())->toBe(0);
});

it('pauses the subscription at trial end when trial_end_behavior is pause', function () {
    ['subscription' => $subscription] = trialingNoCard('pause');

    $this->travelTo(now()->addDays(8));
    app(AdvanceSubscriptionPhases::class)->handle($subscription->fresh());

    $subscription->refresh();

    // trialing → paused; no invoice, resumable once a card arrives.
    expect($subscription->status)->toBe(SubscriptionStatus::Paused)
        ->and($subscription->paused_at)->not->toBeNull()
        ->and($subscription->invoices()->count())->toBe(0);
});

it('opens an invoice at trial end when trial_end_behavior is create_invoice', function () {
    Mail::fake();

    ['fx' => $fx, 'subscription' => $subscription] = trialingNoCard('create_invoice');
    TeamProcessorConnection::factory()->for($fx['team'])->testConnected()->create();
    fakeNombaCheckout('https://checkout.nomba.com/pay/adv07');

    $this->travelTo(now()->addDays(8));
    app(AdvanceSubscriptionPhases::class)->handle($subscription->fresh());

    $subscription->refresh();
    $invoice = $subscription->invoices()->firstOrFail();

    // Stripe missing_payment_method=create_invoice: an open invoice is issued
    // for the first cycle even with no card; the subscription converts.
    expect($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($invoice->status)->toBe(InvoiceStatus::Open)
        ->and($invoice->subtotal)->toBe(500000)
        ->and($invoice->payments()->count())->toBe(0);
});

it('activates the subscription when the open conversion invoice is paid late', function () {
    Mail::fake();

    ['fx' => $fx, 'subscription' => $subscription] = trialingNoCard('create_invoice');
    $team = $fx['team'];
    TeamProcessorConnection::factory()->for($team)->testConnected()->create();
    fakeNombaCheckout('https://checkout.nomba.com/pay/adv07-late');

    // Trial ends with no card → open invoice, subscription active.
    $this->travelTo(now()->addDays(8));
    app(AdvanceSubscriptionPhases::class)->handle($subscription->fresh());

    $invoice = $subscription->invoices()->firstOrFail();
    expect($invoice->status)->toBe(InvoiceStatus::Open);

    // Ten days later the customer adds a card and the outstanding invoice is
    // collected — the subscription settles active with the invoice paid.
    $this->travelTo(now()->addDays(10));
    fakeNombaCharge();
    $card = PaymentMethod::factory()->for($team)->for($fx['amina'])->create(['is_default' => true]);

    app(CollectInvoice::class)->handle($team, $invoice->fresh(), $card);

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and($invoice->fresh()->amount_paid)->toBe(500000)
        ->and($subscription->fresh()->status)->toBe(SubscriptionStatus::Active);
});
