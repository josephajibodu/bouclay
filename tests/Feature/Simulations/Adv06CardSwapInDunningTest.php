<?php

use App\Actions\Dunning\RetryPastDueInvoice;
use App\Enums\InvoiceBillingReason;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionItemStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Subscription;
use App\Models\TeamProcessorConnection;
use App\Services\Invoicing\ClassifyPaymentFailure;

/*
|--------------------------------------------------------------------------
| ADV-06 — Switch payment method while in dunning
|--------------------------------------------------------------------------
|
| BILLING_SIMULATIONS.md ADV-06: updating subscription.payment_method_id
| mid-dunning re-points the retry worker at the new card, and the charge
| routes through the gateway that minted that token (schema.md routing rule).
| Promoted in V2-4.
*/

/**
 * A past-due automatic subscription with one failed attempt on card A, plus a
 * spare card B on the same customer.
 *
 * @return array{sub: Subscription, invoice: Invoice, cardA: PaymentMethod, cardB: PaymentMethod}
 */
function pastDueWithSpareCard(): array
{
    ['team' => $team, 'customer' => $customer, 'price' => $price] = subscriptionFixture();
    TeamProcessorConnection::factory()->for($team)->testConnected()->create();

    $cardA = PaymentMethod::factory()->for($team)->for($customer)->create(['last4' => '1111']);
    $cardB = PaymentMethod::factory()->for($team)->for($customer)->create(['last4' => '2222']);

    $sub = Subscription::factory()->for($team)->for($customer)->create([
        'collection_mode' => 'automatic',
        'payment_method_id' => $cardA->id,
        'status' => SubscriptionStatus::PastDue,
        'current_period_start' => now()->subMonth(),
        'current_period_end' => now()->addWeek(),
    ]);

    $sub->items()->create([
        'price_id' => $price->id,
        'plan_id' => $price->plan_id,
        'product_id' => $price->product_id,
        'quantity' => 1,
        'status' => SubscriptionItemStatus::Active,
    ]);

    $invoice = Invoice::factory()->for($team)->for($customer)->for($sub)->create([
        'status' => InvoiceStatus::Open,
        'billing_reason' => InvoiceBillingReason::SubscriptionCycle,
        'collection_mode' => 'automatic',
        'total' => $price->unit_amount,
        'amount_due' => $price->unit_amount,
    ]);

    Payment::factory()->for($team)->for($invoice)->for($customer)->for($cardA, 'paymentMethod')->create([
        'status' => PaymentStatus::Failed,
        'failure_code' => ClassifyPaymentFailure::INSUFFICIENT_FUNDS,
        'attempt_number' => 1,
        'created_at' => now()->subDays(2),
    ]);

    return ['sub' => $sub->fresh(), 'invoice' => $invoice, 'cardA' => $cardA, 'cardB' => $cardB];
}

it('lets a past_due subscription swap to a different stored card', function () {
    ['sub' => $sub, 'cardB' => $cardB] = pastDueWithSpareCard();

    $sub->update(['payment_method_id' => $cardB->id]);

    expect($sub->fresh()->payment_method_id)->toBe($cardB->id)
        ->and($sub->fresh()->status)->toBe(SubscriptionStatus::PastDue);
});

it('retries the open invoice on the new card and recovers on success', function () {
    ['sub' => $sub, 'invoice' => $invoice, 'cardB' => $cardB] = pastDueWithSpareCard();
    $sub->update(['payment_method_id' => $cardB->id]);

    fakeNombaCharge();
    app(RetryPastDueInvoice::class)->handle($sub->fresh(), force: true);

    // The next attempt charged the swapped card and recovered the sub.
    $recovery = $invoice->payments()->where('status', PaymentStatus::Succeeded)->firstOrFail();

    expect($sub->fresh()->status)->toBe(SubscriptionStatus::Active)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and($recovery->payment_method_id)->toBe($cardB->id);
});

it('keeps charging through the gateway that minted the new token', function () {
    ['sub' => $sub, 'invoice' => $invoice, 'cardB' => $cardB] = pastDueWithSpareCard();
    $sub->update(['payment_method_id' => $cardB->id]);

    fakeNombaCharge();
    app(RetryPastDueInvoice::class)->handle($sub->fresh(), force: true);

    // Tokens are gateway-bound: the retry payment routes via the card's own
    // processor, not the team default (ChargeInvoice resolves by processor).
    $recovery = $invoice->payments()->where('status', PaymentStatus::Succeeded)->firstOrFail();

    expect($recovery->processor)->toBe($cardB->processor);
});
