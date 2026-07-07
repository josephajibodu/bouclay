<?php

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\PaymentLink;
use App\Models\Price;
use App\Models\TeamProcessorConnection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;

test('a product price row can create a reusable hosted payment link', function () {
    ['owner' => $owner, 'team' => $team, 'product' => $product, 'price' => $price] = invoiceFixture();

    $this->actingAs($owner)
        ->post(route('catalog.prices.payment-link', [$product, $price]))
        ->assertRedirect();

    $paymentLink = PaymentLink::query()->firstOrFail();

    expect($paymentLink->team_id)->toBe($team->id)
        ->and($paymentLink->product_id)->toBe($product->id)
        ->and($paymentLink->price_id)->toBe($price->id)
        ->and($paymentLink->url())->toBe(route('hosted.payment-links.show', $paymentLink->public_id));

    $this->actingAs($owner)
        ->post(route('catalog.prices.payment-link', [$product, $price]))
        ->assertRedirect();

    expect(PaymentLink::query()->count())->toBe(1);
});

test('catalog prices include an existing payment link', function () {
    ['owner' => $owner, 'product' => $product, 'price' => $price] = invoiceFixture();

    $paymentLink = PaymentLink::query()->create([
        'team_id' => $price->team_id,
        'product_id' => $product->id,
        'price_id' => $price->id,
    ]);

    $this->actingAs($owner)
        ->get(route('catalog.products.show', $product))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('catalog/show')
            ->where('prices.0.paymentLink.id', $paymentLink->public_id)
            ->where('prices.0.paymentLink.url', $paymentLink->url())
            ->where('prices.0.paymentLink.priceLabel', $price->toPickerLabel())
        );
});

test('a hosted payment link accepts prefilled buyer details', function () {
    ['team' => $team, 'product' => $product, 'price' => $price] = invoiceFixture();

    $paymentLink = PaymentLink::query()->create([
        'team_id' => $team->id,
        'product_id' => $product->id,
        'price_id' => $price->id,
    ]);

    $this->get(route('hosted.payment-links.show', [
        'publicId' => $paymentLink->public_id,
        'email' => ' ADA@example.com ',
        'name' => ' Ada Lovelace ',
    ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('hosted/payment-link')
            ->where('prefill.email', 'ADA@example.com')
            ->where('prefill.name', 'Ada Lovelace')
        );
});

test('a recurring payment link creates a subscription and starts Nomba checkout', function () {
    Mail::fake();

    ['team' => $team, 'product' => $product, 'price' => $price] = invoiceFixture();
    TeamProcessorConnection::factory()->for($team)->testConnected()->create();
    fakeNombaCheckout('https://checkout.nomba.com/pay/recurring-link');

    $paymentLink = PaymentLink::query()->create([
        'team_id' => $team->id,
        'product_id' => $product->id,
        'price_id' => $price->id,
    ]);

    $this->post(route('hosted.payment-links.checkout', $paymentLink->public_id), [
        'name' => 'Ada Lovelace',
        'email' => 'ADA@example.com',
    ])
        ->assertRedirect('https://checkout.nomba.com/pay/recurring-link');

    $customer = Customer::query()->where('email', 'ada@example.com')->firstOrFail();
    $subscription = $customer->subscriptions()->firstOrFail();
    $invoice = $subscription->invoices()->with('lines')->firstOrFail();

    expect($subscription->items()->firstOrFail()->price_id)->toBe($price->id)
        ->and($invoice->lines->first()->price_id)->toBe($price->id)
        ->and($invoice->lines->first()->total)->toBe($price->unit_amount)
        ->and($invoice->custom_data['checkout_link'])->toBe('https://checkout.nomba.com/pay/recurring-link');

    Http::assertSent(function ($request) use ($price) {
        if (! str_contains($request->url(), '/v1/checkout/order')) {
            return false;
        }

        $order = $request->data()['order'];

        return $order['amount'] === number_format($price->unit_amount / 100, 2, '.', '')
            && $order['customerEmail'] === 'ada@example.com'
            && $order['allowedPaymentMethods'] === ['Card'];
    });
});

test('a one-time payment link creates an invoice and starts Nomba checkout', function () {
    ['team' => $team, 'product' => $product] = invoiceFixture();
    TeamProcessorConnection::factory()->for($team)->testConnected()->create();
    $price = Price::factory()->for($team)->for($product)->oneTime()->create([
        'currency' => 'NGN',
        'unit_amount' => 500000,
    ]);
    fakeNombaCheckout('https://checkout.nomba.com/pay/one-time-link');

    $paymentLink = PaymentLink::query()->create([
        'team_id' => $team->id,
        'product_id' => $product->id,
        'price_id' => $price->id,
    ]);

    $this->post(route('hosted.payment-links.checkout', $paymentLink->public_id), [
        'email' => 'buyer@example.com',
    ])
        ->assertRedirect('https://checkout.nomba.com/pay/one-time-link');

    $invoice = Invoice::query()->with(['customer', 'lines'])->firstOrFail();

    expect($invoice->subscription_id)->toBeNull()
        ->and($invoice->customer->email)->toBe('buyer@example.com')
        ->and($invoice->lines->first()->price_id)->toBe($price->id)
        ->and($invoice->lines->first()->total)->toBe(500000)
        ->and($invoice->custom_data['checkout_link'])->toBe('https://checkout.nomba.com/pay/one-time-link');
});
