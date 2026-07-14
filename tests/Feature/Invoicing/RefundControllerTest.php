<?php

use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Refund;
use App\Models\Team;
use App\Models\TeamProcessorConnection;
use App\Models\User;
use App\Services\Gateways\FakeGateway;
use App\Services\Gateways\GatewayManager;

/**
 * A team + owner + a succeeded ₦5,000 payment, with the FakeGateway bound.
 *
 * @return array{owner: User, team: Team, invoice: Invoice, payment: Payment}
 */
function refundControllerFixture(): array
{
    $owner = User::factory()->create();
    $team = Team::factory()->create(['default_currency' => 'NGN']);
    attachTeamOwner($team, $owner);
    $owner->switchTeam($team);

    app(GatewayManager::class)->extend('nomba', FakeGateway::class);
    FakeGateway::reset();

    TeamProcessorConnection::factory()->for($team)->testConnected()->create();
    $customer = $team->customers()->create(['name' => 'A', 'email' => 'a@test.test', 'currency' => 'NGN']);
    $card = PaymentMethod::factory()->for($team)->for($customer)->create();
    $invoice = Invoice::factory()->for($team)->for($customer)->create(['currency' => 'NGN']);
    $payment = Payment::factory()->for($team)->for($invoice)->for($customer)->create([
        'payment_method_id' => $card->id,
        'amount' => 500000,
        'currency' => 'NGN',
        'status' => PaymentStatus::Succeeded,
    ]);

    return compact('owner', 'team', 'invoice', 'payment');
}

test('an authorized user can refund a payment', function () {
    ['owner' => $owner, 'invoice' => $invoice, 'payment' => $payment] = refundControllerFixture();

    $this->actingAs($owner)
        ->post(route('invoices.payments.refund', [$invoice, $payment]), [
            'amount' => 2000, // ₦2,000 major units
            'reason' => 'Goodwill',
        ])
        ->assertRedirect();

    $refund = Refund::query()->firstOrFail();
    expect($refund->amount)->toBe(200000)
        ->and($refund->status)->toBe(RefundStatus::Succeeded)
        ->and($refund->reason)->toBe('Goodwill')
        ->and($payment->fresh()->status)->toBe(PaymentStatus::Succeeded);
});

test('a member without refunds.process cannot refund', function () {
    ['team' => $team, 'invoice' => $invoice, 'payment' => $payment] = refundControllerFixture();
    $member = User::factory()->create();
    attachTeamMember($team, $member, 'Finance');
    $member->switchTeam($team);

    $this->actingAs($member)
        ->post(route('invoices.payments.refund', [$invoice, $payment]), ['amount' => 2000])
        ->assertForbidden();

    expect(Refund::query()->count())->toBe(0);
});

test('refunding more than the payment is rejected', function () {
    ['owner' => $owner, 'invoice' => $invoice, 'payment' => $payment] = refundControllerFixture();

    $this->actingAs($owner)
        ->post(route('invoices.payments.refund', [$invoice, $payment]), ['amount' => 6000])
        ->assertSessionHasErrors('amount');
});

test('a payment from another team cannot be refunded', function () {
    ['owner' => $owner, 'invoice' => $invoice] = refundControllerFixture();
    ['payment' => $foreignPayment, 'invoice' => $foreignInvoice] = refundControllerFixture();

    $this->actingAs($owner)
        ->post(route('invoices.payments.refund', [$invoice, $foreignPayment]), ['amount' => 1000])
        ->assertNotFound();
});
