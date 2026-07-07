<?php

use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\TeamProcessorConnection;
use Illuminate\Support\Facades\Cache;

test('checkout session creates invoice-backed intent and completes via hosted callback', function () {
    ['token' => $token, 'team' => $team] = apiAuthFixture();
    TeamProcessorConnection::factory()->for($team)->testConnected()->create();
    fakeNombaCheckout('https://checkout.nomba.com/pay/api-session');

    $customer = Customer::factory()->for($team)->create(['currency' => 'NGN']);

    $create = $this->postJson('/api/v1/checkout-sessions', [
        'customer' => $customer->public_id,
    ], apiHeaders($token, 'checkout-create-1'));

    $create->assertCreated()
        ->assertJsonPath('data.status', 'open')
        ->assertJsonPath('data.checkoutUrl', 'https://checkout.nomba.com/pay/api-session');

    $orderReference = $create->json('data.id');

    $intent = Cache::get("nomba_checkout:{$orderReference}");

    expect($intent)->toBeArray()
        ->and($intent['invoice_id'] ?? null)->not->toBeNull()
        ->and($intent['api_checkout_session'] ?? null)->toBeTrue();

    $this->getJson("/api/v1/checkout-sessions/{$orderReference}", apiHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.status', 'open');

    $this->get(route('hosted.checkout.callback', ['orderReference' => $orderReference]))
        ->assertRedirect();

    $customer->refresh();

    expect($customer->paymentMethods()->count())->toBe(1);

    $this->getJson("/api/v1/checkout-sessions/{$orderReference}", apiHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.status', 'complete');

    expect(Cache::has("nomba_checkout_completed:{$orderReference}"))->toBeTrue();

    $invoice = $customer->invoices()->firstOrFail();

    expect($invoice->status)->toBe(InvoiceStatus::Paid)
        ->and($invoice->payments()->firstOrFail()->status)->toBe(PaymentStatus::Succeeded);
});
