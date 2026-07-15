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
use Inertia\Testing\AssertableInertia;

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

/*
|--------------------------------------------------------------------------
| Refund state on the invoice page (IMPLEMENTATION_V2 §V2-4)
|--------------------------------------------------------------------------
|
| The dashboard gates the refund action on the gateway's own capabilities(),
| so a gateway that can't refund renders it disabled with copy rather than
| failing mid-flight.
*/

test('the invoice page exposes what is still refundable on a payment', function () {
    ['owner' => $owner, 'invoice' => $invoice, 'payment' => $payment] = refundControllerFixture();

    Refund::factory()->for($payment)->create([
        'team_id' => $payment->team_id,
        'invoice_id' => $invoice->id,
        'amount' => 200000,
        'currency' => 'NGN',
        'status' => RefundStatus::Succeeded,
        'reason' => 'Goodwill',
    ]);

    $this->actingAs($owner)
        ->get(route('invoices.show', $invoice))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('invoices/show')
            ->where('invoice.payments.0.refundedAmount', 200000)
            ->where('invoice.payments.0.refundableAmount', 300000)
            ->where('invoice.payments.0.refunds.0.amount', 200000)
            ->where('invoice.payments.0.refunds.0.reason', 'Goodwill')
            ->where('permissions.canProcessRefunds', true),
        );
});

test('a failed refund does not count against the refundable balance', function () {
    ['owner' => $owner, 'invoice' => $invoice, 'payment' => $payment] = refundControllerFixture();

    Refund::factory()->for($payment)->create([
        'team_id' => $payment->team_id,
        'invoice_id' => $invoice->id,
        'amount' => 200000,
        'currency' => 'NGN',
        'status' => RefundStatus::Failed,
    ]);

    // The gateway declined it, so no money moved — the whole charge is still
    // refundable.
    $this->actingAs($owner)
        ->get(route('invoices.show', $invoice))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('invoice.payments.0.refundedAmount', 0)
            ->where('invoice.payments.0.refundableAmount', 500000),
        );
});

test('the page advertises the refund support of the gateway that took the charge', function () {
    ['owner' => $owner, 'invoice' => $invoice] = refundControllerFixture();

    $this->actingAs($owner)
        ->get(route('invoices.show', $invoice))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('invoice.payments.0.refundSupport.refunds', true)
            ->where('invoice.payments.0.refundSupport.partialRefunds', true)
            ->where('invoice.payments.0.refundSupport.gatewayLabel', 'Fake Gateway'),
        );
});

test('a member without refunds.process sees no refund permission', function () {
    ['team' => $team, 'invoice' => $invoice] = refundControllerFixture();

    // Finance can see the invoice and its payments, but holds no
    // refunds.process — the action must not be offered.
    $member = User::factory()->create();
    attachTeamMember($team, $member, 'Finance');
    $member->switchTeam($team);

    $this->actingAs($member)
        ->get(route('invoices.show', $invoice))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('permissions.canProcessRefunds', false),
        );
});
