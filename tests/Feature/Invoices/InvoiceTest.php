<?php

use App\Enums\AddressType;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Models\Address;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Price;
use App\Models\Product;
use App\Models\Team;
use App\Models\TeamProcessorConnection;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @return array{team: Team, owner: User, customer: Customer, product: Product, price: Price}
 */
function invoiceFixture(): array
{
    $owner = User::factory()->create();
    $team = Team::factory()->create(['default_currency' => 'NGN']);
    attachTeamOwner($team, $owner);
    $owner->switchTeam($team);

    $product = Product::factory()->for($team)->create(['name' => 'Pro']);
    $price = Price::factory()->for($team)->for($product)->create(['currency' => 'NGN']);
    $customer = Customer::factory()->for($team)->create(['currency' => 'NGN']);

    return compact('team', 'owner', 'customer', 'product', 'price');
}

test('the invoices index renders', function () {
    ['owner' => $owner] = invoiceFixture();

    $this->actingAs($owner)
        ->get(route('invoices.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('invoices/index')
            ->where('hasAny', false)
            ->where('canManage', true));
});

test('a manual invoice with no charge attempt is still visible in the list', function () {
    ['owner' => $owner, 'customer' => $customer, 'price' => $price] = invoiceFixture();

    $this->actingAs($owner)
        ->post(route('invoices.store'), [
            'customer_id' => $customer->id,
            'collection_mode' => 'manual',
            'items' => [['price_id' => $price->id]],
        ]);

    expect(Payment::query()->count())->toBe(0);

    $this->actingAs($owner)
        ->get(route('invoices.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('invoices/index')
            ->where('hasAny', true)
            ->has('invoices.data', 1)
            ->where('invoices.data.0.status', 'open')
            ->where('invoices.data.0.customer.email', $customer->email));
});

test('a member without the invoices permission cannot view the list', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();
    attachTeamOwner($team, $owner);
    attachTeamMember($team, $member, 'Developer');
    $member->switchTeam($team);

    $this->actingAs($member)
        ->get(route('invoices.index'))
        ->assertForbidden();
});

test('the invoice show page renders', function () {
    ['owner' => $owner, 'customer' => $customer, 'price' => $price] = invoiceFixture();

    $this->actingAs($owner)
        ->post(route('invoices.store'), [
            'customer_id' => $customer->id,
            'collection_mode' => 'manual',
            'items' => [['price_id' => $price->id]],
        ]);

    $invoice = $customer->invoices()->firstOrFail();

    $this->actingAs($owner)
        ->get(route('invoices.show', $invoice))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('invoices/show')
            ->has('invoice.lines', 1)
            ->where('invoice.status', 'open')
            ->where('invoice.customer.email', $customer->email)
            ->where('permissions.canManage', true));
});

test('creating an invoice snapshots the customer and billing address', function () {
    ['owner' => $owner, 'team' => $team, 'customer' => $customer, 'price' => $price] = invoiceFixture();

    Address::factory()->for($team)->for($customer)->create([
        'type' => AddressType::Billing,
        'line1' => '12 Akobo Road',
        'city' => 'Ibadan',
        'country' => 'NG',
        'is_default' => true,
    ]);

    $this->actingAs($owner)
        ->post(route('invoices.store'), [
            'customer_id' => $customer->id,
            'collection_mode' => 'manual',
            'items' => [['price_id' => $price->id]],
        ]);

    $invoice = $customer->invoices()->firstOrFail();

    expect($invoice->customer_snapshot)->toMatchArray([
        'name' => $customer->name,
        'email' => $customer->email,
    ])
        ->and($invoice->billing_address)->not->toBeNull()
        ->and($invoice->billing_address['line1'])->toBe('12 Akobo Road')
        ->and($invoice->billing_address['singleLine'])->toContain('Ibadan');
});

test('the show page reads from the snapshot, not the live customer', function () {
    ['owner' => $owner, 'team' => $team, 'customer' => $customer, 'price' => $price] = invoiceFixture();

    Address::factory()->for($team)->for($customer)->create([
        'type' => AddressType::Billing,
        'line1' => 'Original Street',
        'country' => 'NG',
        'is_default' => true,
    ]);

    $this->actingAs($owner)
        ->post(route('invoices.store'), [
            'customer_id' => $customer->id,
            'collection_mode' => 'manual',
            'items' => [['price_id' => $price->id]],
        ]);

    $invoice = $customer->invoices()->firstOrFail();
    $customer->update(['name' => 'Changed Name', 'email' => 'changed@example.com']);
    $customer->addresses()->delete();

    $this->actingAs($owner)
        ->get(route('invoices.show', $invoice))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('invoice.customer.name', $invoice->customer_snapshot['name'])
            ->where('invoice.customer.email', $invoice->customer_snapshot['email'])
            ->where('invoice.billingAddress.line1', 'Original Street'));
});

test('a manual invoice with a price line creates an open invoice and no payment', function () {
    ['owner' => $owner, 'customer' => $customer, 'price' => $price] = invoiceFixture();

    $this->actingAs($owner)
        ->post(route('invoices.store'), [
            'customer_id' => $customer->id,
            'collection_mode' => 'manual',
            'items' => [['price_id' => $price->id, 'quantity' => 2]],
        ])
        ->assertRedirect(route('invoices.show', $customer->invoices()->firstOrFail()));

    $invoice = $customer->invoices()->firstOrFail();

    expect($invoice->status)->toBe(InvoiceStatus::Open)
        ->and($invoice->total)->toBe($price->unit_amount * 2)
        ->and($invoice->due_at)->not->toBeNull()
        ->and($invoice->lines)->toHaveCount(1)
        ->and($invoice->lines->first()->quantity)->toBe(2)
        ->and($invoice->payments)->toHaveCount(0);
});

test('invoice numbers are real sequential numbers, not a bare dash', function () {
    ['owner' => $owner, 'customer' => $customer, 'price' => $price] = invoiceFixture();

    $payload = [
        'customer_id' => $customer->id,
        'collection_mode' => 'manual',
        'items' => [['price_id' => $price->id]],
    ];

    $this->actingAs($owner)->post(route('invoices.store'), $payload);
    $this->actingAs($owner)->post(route('invoices.store'), $payload);

    $numbers = $customer->invoices()->orderBy('id')->pluck('number');

    expect($numbers)->toHaveCount(2)
        ->and($numbers[0])->toMatch('/^[A-Za-z]+-\d+$/')
        ->and($numbers[1])->toMatch('/^[A-Za-z]+-\d+$/')
        ->and($numbers[0])->not->toBe($numbers[1]);
});

test('an automatic invoice with a card on file is charged and marked paid', function () {
    ['owner' => $owner, 'team' => $team, 'customer' => $customer, 'price' => $price] = invoiceFixture();
    $card = PaymentMethod::factory()->for($team)->for($customer)->create();
    TeamProcessorConnection::factory()->for($team)->testConnected()->create();
    fakeNombaCharge();

    $this->actingAs($owner)
        ->post(route('invoices.store'), [
            'customer_id' => $customer->id,
            'collection_mode' => 'automatic',
            'payment_method_id' => $card->id,
            'items' => [['price_id' => $price->id]],
        ])
        ->assertRedirect(route('invoices.show', $customer->invoices()->firstOrFail()));

    $invoice = $customer->invoices()->firstOrFail();
    $payment = $invoice->payments()->firstOrFail();

    expect($invoice->status)->toBe(InvoiceStatus::Paid)
        ->and($invoice->amount_paid)->toBe($invoice->total)
        ->and($payment->status)->toBe(PaymentStatus::Succeeded)
        ->and($payment->public_id)->toStartWith('pay_');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/v1/checkout/tokenized-card-payment'));
});

test('a declined automatic invoice leaves the invoice open with a failed payment', function () {
    ['owner' => $owner, 'team' => $team, 'customer' => $customer, 'price' => $price] = invoiceFixture();
    $card = PaymentMethod::factory()->for($team)->for($customer)->create();
    TeamProcessorConnection::factory()->for($team)->testConnected()->create();
    fakeNombaCharge(approved: false);

    $this->actingAs($owner)
        ->post(route('invoices.store'), [
            'customer_id' => $customer->id,
            'collection_mode' => 'automatic',
            'payment_method_id' => $card->id,
            'items' => [['price_id' => $price->id]],
        ]);

    $invoice = $customer->invoices()->firstOrFail();
    $payment = $invoice->payments()->firstOrFail();

    expect($invoice->status)->toBe(InvoiceStatus::Open)
        ->and($payment->status)->toBe(PaymentStatus::Failed)
        ->and($payment->failure_reason)->not->toBeNull();
});

test('an open invoice can be voided', function () {
    ['owner' => $owner, 'customer' => $customer, 'price' => $price] = invoiceFixture();

    $this->actingAs($owner)
        ->post(route('invoices.store'), [
            'customer_id' => $customer->id,
            'collection_mode' => 'manual',
            'items' => [['price_id' => $price->id]],
        ]);

    $invoice = $customer->invoices()->firstOrFail();

    $this->actingAs($owner)
        ->post(route('invoices.void', $invoice))
        ->assertRedirect(route('invoices.show', $invoice));

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Void)
        ->and($invoice->fresh()->voided_at)->not->toBeNull()
        ->and($invoice->fresh()->amount_due)->toBe(0);
});

test('the customer hub lists invoices', function () {
    ['owner' => $owner, 'customer' => $customer, 'price' => $price] = invoiceFixture();

    $this->actingAs($owner)
        ->post(route('invoices.store'), [
            'customer_id' => $customer->id,
            'collection_mode' => 'manual',
            'items' => [['price_id' => $price->id]],
        ]);

    $this->actingAs($owner)
        ->get(route('customers.show', $customer))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('invoices', 1)
            ->where('invoices.0.status', 'open')
            ->has('invoices.0.productsLabel'));
});

test('invoices from another team return 404', function () {
    ['owner' => $owner, 'customer' => $customer] = invoiceFixture();

    $otherTeam = Team::factory()->create();
    $otherOwner = User::factory()->create();
    attachTeamOwner($otherTeam, $otherOwner);
    $otherOwner->switchTeam($otherTeam);

    $invoice = Invoice::factory()->for($owner->currentTeam)->for($customer)->create();

    $this->actingAs($otherOwner)
        ->get(route('invoices.show', $invoice))
        ->assertNotFound();
});
