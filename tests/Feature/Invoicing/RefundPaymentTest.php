<?php

use App\Actions\Invoicing\RefundPayment;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Refund;
use App\Models\TeamProcessorConnection;
use App\Services\Gateways\FakeGateway;
use App\Services\Gateways\GatewayManager;

/*
|--------------------------------------------------------------------------
| RefundPayment — reversal through the gateway driver (schema.md §8)
|--------------------------------------------------------------------------
|
| Refunds route through the gateway that minted the token; a Refund row is
| its own auditable record and the source payment flips to `refunded` only
| when fully reversed. Uses the FakeGateway so no network is touched.
*/

/**
 * A succeeded ₦5,000 payment on a connected team, with the FakeGateway bound.
 *
 * @return array{payment: Payment}
 */
function refundablePayment(int $amount = 500000): array
{
    ['team' => $team, 'customer' => $customer] = invoiceFixture();
    app(GatewayManager::class)->extend('nomba', FakeGateway::class);
    FakeGateway::reset();

    TeamProcessorConnection::factory()->for($team)->testConnected()->create();
    $card = PaymentMethod::factory()->for($team)->for($customer)->create();
    $invoice = Invoice::factory()->for($team)->for($customer)->create(['currency' => 'NGN']);

    $payment = Payment::factory()->for($team)->for($invoice)->for($customer)->create([
        'payment_method_id' => $card->id,
        'amount' => $amount,
        'currency' => 'NGN',
        'status' => PaymentStatus::Succeeded,
    ]);

    return ['payment' => $payment];
}

it('records a partial refund as its own row and leaves the charge succeeded', function () {
    ['payment' => $payment] = refundablePayment(500000);

    $refund = app(RefundPayment::class)->handle($payment, 200000, 'Goodwill');

    expect($refund->amount)->toBe(200000)
        ->and($refund->status)->toBe(RefundStatus::Succeeded)
        ->and($refund->reason)->toBe('Goodwill')
        ->and($refund->processor_reference)->not->toBeNull()
        // A partial refund does not touch the source charge.
        ->and($payment->fresh()->status)->toBe(PaymentStatus::Succeeded)
        ->and(FakeGateway::$refunds)->toHaveCount(1);
});

it('marks the payment refunded once fully reversed', function () {
    ['payment' => $payment] = refundablePayment(500000);

    app(RefundPayment::class)->handle($payment, 500000);

    expect($payment->fresh()->status)->toBe(PaymentStatus::Refunded);
});

it('marks the payment refunded when partial refunds cumulatively cover it', function () {
    ['payment' => $payment] = refundablePayment(500000);

    app(RefundPayment::class)->handle($payment, 300000);
    expect($payment->fresh()->status)->toBe(PaymentStatus::Succeeded);

    app(RefundPayment::class)->handle($payment, 200000);
    expect($payment->fresh()->status)->toBe(PaymentStatus::Refunded)
        ->and(Refund::query()->count())->toBe(2);
});

it('refuses to refund more than the remaining refundable amount', function () {
    ['payment' => $payment] = refundablePayment(500000);
    app(RefundPayment::class)->handle($payment, 400000);

    // Only 100000 remains.
    expect(fn () => app(RefundPayment::class)->handle($payment, 200000))
        ->toThrow(InvalidArgumentException::class);
});

it('refuses to refund a payment that did not succeed', function () {
    ['payment' => $payment] = refundablePayment(500000);
    $payment->update(['status' => PaymentStatus::Failed]);

    expect(fn () => app(RefundPayment::class)->handle($payment, 100000))
        ->toThrow(InvalidArgumentException::class, 'succeeded');
});

it('writes a failed refund row without touching the charge when the gateway declines', function () {
    ['payment' => $payment] = refundablePayment(500000);
    FakeGateway::$approveRefunds = false;

    $refund = app(RefundPayment::class)->handle($payment, 500000);

    expect($refund->status)->toBe(RefundStatus::Failed)
        ->and($payment->fresh()->status)->toBe(PaymentStatus::Succeeded);
});
