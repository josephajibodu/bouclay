<?php

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;

test('api responses return amounts in major currency units', function () {
    ['token' => $token, 'team' => $team] = apiAuthFixture();
    $customer = Customer::factory()->for($team)->create(['currency' => 'NGN']);
    $product = Product::factory()->for($team)->create();
    $plan = Plan::factory()->for($team)->for($product)->create();

    $priceResponse = $this->postJson("/api/v1/products/{$product->public_id}/prices", [
        'planId' => $plan->public_id,
        'type' => 'recurring',
        'pricingModel' => 'standard',
        'unitAmount' => 15000,
        'currency' => 'NGN',
        'billingInterval' => 'month',
    ], apiHeaders($token, 'amount-price-1'));

    $priceResponse->assertCreated()
        ->assertJsonPath('data.unitAmount', fn ($amount) => (float) $amount === 15000.0);

    $price = Price::query()->where('team_id', $team->id)->firstOrFail();
    expect($price->unit_amount)->toBe(1_500_000);

    $this->getJson('/api/v1/prices/'.$price->public_id, apiHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.unitAmount', fn ($amount) => (float) $amount === 15000.0);

    $invoiceResponse = $this->postJson('/api/v1/invoices', [
        'customer' => $customer->public_id,
        'collectionMode' => 'manual',
        'items' => [
            ['priceId' => $price->public_id, 'quantity' => 1],
        ],
    ], apiHeaders($token, 'amount-invoice-1'));

    $invoiceResponse->assertCreated()
        ->assertJsonPath('data.total', fn ($amount) => (float) $amount === 15000.0)
        ->assertJsonPath('data.lines.0.unitAmount', fn ($amount) => (float) $amount === 15000.0);

    $invoice = Invoice::query()->where('team_id', $team->id)->firstOrFail();

    $payment = Payment::factory()->for($team)->for($customer)->create([
        'invoice_id' => $invoice->id,
        'amount' => $invoice->total,
        'currency' => 'NGN',
    ]);

    $this->getJson('/api/v1/payments/'.$payment->public_id, apiHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.amount', fn ($amount) => (float) $amount === 15000.0);
});

test('archived product reports archived status in api responses', function () {
    ['token' => $token, 'team' => $team] = apiAuthFixture();

    $product = Product::factory()->for($team)->create();

    $this->postJson('/api/v1/products/'.$product->public_id.'/archive', [], apiHeaders($token, 'archive-product-1'))
        ->assertOk()
        ->assertJsonPath('data.status', 'archived')
        ->assertJsonPath('data.archivedAt', fn ($value) => $value !== null);
});

test('recurring api prices require billing interval', function () {
    ['token' => $token, 'team' => $team] = apiAuthFixture();
    $product = Product::factory()->for($team)->create();
    $plan = Plan::factory()->for($team)->for($product)->create();

    $this->postJson("/api/v1/products/{$product->public_id}/prices", [
        'planId' => $plan->public_id,
        'type' => 'recurring',
        'pricingModel' => 'standard',
        'unitAmount' => 1000,
        'currency' => 'NGN',
    ], apiHeaders($token, 'missing-interval-1'))
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'invalid_field')
        ->assertJsonFragment(['field' => 'billingInterval']);
});

test('recurring api prices require a plan', function () {
    ['token' => $token, 'team' => $team] = apiAuthFixture();
    $product = Product::factory()->for($team)->create();

    $this->postJson("/api/v1/products/{$product->public_id}/prices", [
        'type' => 'recurring',
        'pricingModel' => 'standard',
        'unitAmount' => 1000,
        'currency' => 'NGN',
        'billingInterval' => 'month',
    ], apiHeaders($token, 'missing-plan-1'))
        ->assertUnprocessable()
        ->assertJsonFragment(['field' => 'planId']);
});
