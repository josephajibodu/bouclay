<?php

use App\Enums\InvoiceStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\PaymentMethod;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('a customer receives a portal token when created', function () {
    $team = Team::factory()->create();
    $customer = Customer::factory()->for($team)->create();

    expect($customer->portal_token)->not->toBeEmpty();
    expect($customer->portal_token)->toHaveLength(48);
});

test('the portal dashboard renders for a valid portal token', function () {
    $team = Team::factory()->create(['name' => 'Acme Notes']);
    $customer = Customer::factory()->for($team)->create([
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
    ]);

    $this->get(route('portal.show', $customer->portal_token))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('portal/dashboard')
            ->where('business.name', 'Acme Notes')
            ->where('customer.email', 'ada@example.com')
            ->where('customer.name', 'Ada Lovelace')
            ->where('paymentMethod', null)
            ->has('subscriptions', 0)
            ->has('openInvoices', 0));
});

test('an invalid portal token returns not found', function () {
    $this->get(route('portal.show', 'invalid-token'))
        ->assertNotFound();
});

test('archived customers cannot access the portal', function () {
    $team = Team::factory()->create();
    $customer = Customer::factory()->for($team)->create();
    $token = $customer->portal_token;

    $customer->delete();

    $this->get(route('portal.show', $token))
        ->assertNotFound();
});

test('the portal dashboard shows subscriptions payment method and open invoices', function () {
    $team = Team::factory()->create(['name' => 'Acme Notes', 'default_currency' => 'NGN']);
    $customer = Customer::factory()->for($team)->create(['currency' => 'NGN']);

    $paymentMethod = PaymentMethod::factory()->for($team)->for($customer)->create([
        'brand' => 'Visa',
        'last4' => '4242',
        'exp_month' => 12,
        'exp_year' => 2030,
        'is_default' => true,
    ]);

    $customer->update(['default_payment_method_id' => $paymentMethod->id]);

    $subscription = Subscription::factory()->for($team)->for($customer)->create([
        'status' => SubscriptionStatus::Active,
        'current_period_end' => now()->addMonth(),
    ]);

    $invoice = Invoice::factory()->for($team)->for($customer)->create([
        'status' => InvoiceStatus::Open,
        'currency' => 'NGN',
        'amount_due' => 500000,
        'total' => 500000,
    ]);

    $this->get(route('portal.show', $customer->portal_token))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('portal/dashboard')
            ->where('paymentMethod.brand', 'Visa')
            ->where('paymentMethod.last4', '4242')
            ->has('subscriptions', 1)
            ->where('subscriptions.0.publicId', $subscription->public_id)
            ->where('subscriptions.0.status', 'active')
            ->has('openInvoices', 1)
            ->where('openInvoices.0.publicId', $invoice->public_id)
            ->where('openInvoices.0.payUrl', route('hosted.invoices.show', $invoice->public_id)));
});

test('the customer hub exposes the portal url', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $customer = Customer::factory()->for($team)->create();

    attachTeamOwner($team, $owner);
    $owner->switchTeam($team);

    $this->actingAs($owner)
        ->get(route('customers.show', $customer))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('portalUrl', $customer->portalUrl()));
});

test('new customers get a portal token via the store endpoint', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();

    attachTeamOwner($team, $owner);
    $owner->switchTeam($team);

    $this->actingAs($owner)
        ->post(route('customers.store'), ['email' => 'portal@example.com']);

    $customer = Customer::query()->where('email', 'portal@example.com')->firstOrFail();

    expect($customer->portal_token)->not->toBeEmpty();
});
