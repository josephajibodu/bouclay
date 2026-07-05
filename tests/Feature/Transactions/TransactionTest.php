<?php

use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Models\Customer;
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
 * A team + owner with a recurring price and a customer, all in NGN.
 *
 * @return array{team: Team, owner: User, customer: Customer, product: Product, price: Price}
 */
function transactionFixture(): array
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

test('the transactions index renders', function () {
    ['owner' => $owner] = transactionFixture();

    $this->actingAs($owner)
        ->get(route('transactions.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('transactions/index')
            ->where('hasAny', false)
            ->where('canManage', true));
});

test('a manual transaction with no charge attempt is still visible in the list', function () {
    // Regression: the list used to query `payments`, so a manually-billed
    // invoice with zero charge attempts was invisible the moment it was
    // created — the bug this test pins down.
    ['owner' => $owner, 'customer' => $customer, 'price' => $price] = transactionFixture();

    $this->actingAs($owner)
        ->post(route('transactions.store'), [
            'customer_id' => $customer->id,
            'collection_mode' => 'manual',
            'items' => [['price_id' => $price->id]],
        ]);

    expect(Payment::query()->count())->toBe(0);

    $this->actingAs($owner)
        ->get(route('transactions.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('transactions/index')
            ->where('hasAny', true)
            ->has('transactions.data', 1)
            ->where('transactions.data.0.status', 'open')
            ->where('transactions.data.0.customer.email', $customer->email));
});

test('a member without the transactions permission cannot view the page', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();
    attachTeamOwner($team, $owner);
    // Developer carries no invoices.* permission (app/Actions/Teams/SeedDefaultRoles).
    attachTeamMember($team, $member, 'Developer');
    $member->switchTeam($team);

    $this->actingAs($member)
        ->get(route('transactions.index'))
        ->assertForbidden();
});

test('a manual transaction with a price line creates an open invoice and no payment', function () {
    ['owner' => $owner, 'customer' => $customer, 'price' => $price] = transactionFixture();

    $this->actingAs($owner)
        ->post(route('transactions.store'), [
            'customer_id' => $customer->id,
            'collection_mode' => 'manual',
            'items' => [['price_id' => $price->id, 'quantity' => 2]],
        ])
        ->assertRedirect(route('transactions.index'));

    $invoice = $customer->invoices()->firstOrFail();

    expect($invoice->status)->toBe(InvoiceStatus::Open)
        ->and($invoice->total)->toBe($price->unit_amount * 2)
        ->and($invoice->due_at)->not->toBeNull()
        ->and($invoice->lines)->toHaveCount(1)
        ->and($invoice->lines->first()->quantity)->toBe(2)
        ->and($invoice->payments)->toHaveCount(0);
});

test('a manual transaction with a custom line item works', function () {
    ['owner' => $owner, 'customer' => $customer] = transactionFixture();

    $this->actingAs($owner)
        ->post(route('transactions.store'), [
            'customer_id' => $customer->id,
            'collection_mode' => 'manual',
            'items' => [['description' => 'Consulting', 'unit_amount' => 150.5, 'quantity' => 1]],
        ])
        ->assertRedirect(route('transactions.index'));

    $invoice = $customer->invoices()->firstOrFail();

    expect($invoice->lines->first()->description)->toBe('Consulting')
        ->and($invoice->total)->toBe(15050);
});

test('invoice numbers are real sequential numbers, not a bare dash', function () {
    ['owner' => $owner, 'customer' => $customer, 'price' => $price] = transactionFixture();

    $payload = [
        'customer_id' => $customer->id,
        'collection_mode' => 'manual',
        'items' => [['price_id' => $price->id]],
    ];

    $this->actingAs($owner)->post(route('transactions.store'), $payload);
    $this->actingAs($owner)->post(route('transactions.store'), $payload);

    $numbers = $customer->invoices()->orderBy('id')->pluck('number');

    expect($numbers)->toHaveCount(2)
        ->and($numbers[0])->toMatch('/^[A-Za-z]+-\d+$/')
        ->and($numbers[1])->toMatch('/^[A-Za-z]+-\d+$/')
        ->and($numbers[0])->not->toBe($numbers[1]);
});

test('an automatic transaction with a card on file is really charged and marks the invoice paid', function () {
    ['owner' => $owner, 'team' => $team, 'customer' => $customer, 'price' => $price] = transactionFixture();
    $card = PaymentMethod::factory()->for($team)->for($customer)->create();
    TeamProcessorConnection::factory()->for($team)->testConnected()->create();
    fakeNombaCharge();

    $this->actingAs($owner)
        ->post(route('transactions.store'), [
            'customer_id' => $customer->id,
            'collection_mode' => 'automatic',
            'payment_method_id' => $card->id,
            'items' => [['price_id' => $price->id]],
        ])
        ->assertRedirect(route('transactions.index'));

    $invoice = $customer->invoices()->firstOrFail();
    $payment = $invoice->payments()->firstOrFail();

    expect($invoice->status)->toBe(InvoiceStatus::Paid)
        ->and($invoice->amount_paid)->toBe($invoice->total)
        ->and($payment->status)->toBe(PaymentStatus::Succeeded)
        ->and($payment->public_id)->toStartWith('txn_');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/v1/checkout/tokenized-card-payment'));
});

test('a declined automatic transaction leaves the invoice open with a failed transaction', function () {
    ['owner' => $owner, 'team' => $team, 'customer' => $customer, 'price' => $price] = transactionFixture();
    $card = PaymentMethod::factory()->for($team)->for($customer)->create();
    TeamProcessorConnection::factory()->for($team)->testConnected()->create();
    fakeNombaCharge(approved: false);

    $this->actingAs($owner)
        ->post(route('transactions.store'), [
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

test('an automatic transaction with no card creates an open invoice with no payment attempt', function () {
    ['owner' => $owner, 'customer' => $customer, 'price' => $price] = transactionFixture();

    $this->actingAs($owner)
        ->post(route('transactions.store'), [
            'customer_id' => $customer->id,
            'collection_mode' => 'automatic',
            'items' => [['price_id' => $price->id]],
        ])
        ->assertRedirect(route('transactions.index'));

    $invoice = $customer->invoices()->firstOrFail();

    expect($invoice->status)->toBe(InvoiceStatus::Open)
        ->and($invoice->payments)->toHaveCount(0);
});

test('a price in a different currency than the customer is rejected', function () {
    ['owner' => $owner, 'team' => $team, 'customer' => $customer] = transactionFixture();
    $usdProduct = Product::factory()->for($team)->create();
    $usdPrice = Price::factory()->for($team)->for($usdProduct)->create(['currency' => 'USD']);

    $this->actingAs($owner)
        ->post(route('transactions.store'), [
            'customer_id' => $customer->id,
            'collection_mode' => 'manual',
            'items' => [['price_id' => $usdPrice->id]],
        ])
        ->assertSessionHasErrors('items');

    expect($customer->invoices()->count())->toBe(0);
});

test('a transaction cannot be created without any items', function () {
    ['owner' => $owner, 'customer' => $customer] = transactionFixture();

    $this->actingAs($owner)
        ->post(route('transactions.store'), [
            'customer_id' => $customer->id,
            'collection_mode' => 'manual',
            'items' => [],
        ])
        ->assertSessionHasErrors('items');
});

test("a customer's total spend reflects only succeeded transactions", function () {
    ['team' => $team, 'customer' => $customer] = transactionFixture();

    Payment::factory()->for($team)->for($customer)->create(['amount' => 10_000]);
    Payment::factory()->for($team)->for($customer)->failed()->create(['amount' => 50_000]);

    expect($customer->fresh()->totalSpend())->toBe(10_000);
});
