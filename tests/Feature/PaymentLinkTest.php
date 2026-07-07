<?php

use App\Enums\InvoiceStatus;
use App\Enums\OutboundEventType;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Customer;
use App\Models\Event;
use App\Models\Invoice;
use App\Models\PaymentLink;
use App\Models\Price;
use App\Models\Subscription;
use App\Models\SubscriptionItemTrial;
use App\Models\TeamProcessorConnection;
use App\Models\TrialOffer;
use Illuminate\Support\Facades\Http;
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

test('a trial offer can create a reusable hosted trial link', function () {
    ['owner' => $owner, 'team' => $team, 'product' => $product, 'price' => $transitionPrice] = invoiceFixture();
    $trialPrice = Price::factory()->for($team)->for($product)->free()->create([
        'currency' => $transitionPrice->currency,
        'billing_interval' => 'day',
        'billing_frequency' => 14,
    ]);
    $trialOffer = TrialOffer::factory()->create([
        'team_id' => $team->id,
        'product_id' => $product->id,
        'trial_price_id' => $trialPrice->id,
        'transition_product_id' => $product->id,
        'transition_price_id' => $transitionPrice->id,
    ]);

    $this->actingAs($owner)
        ->post(route('catalog.trials.payment-link', [$product, $trialOffer]))
        ->assertRedirect();

    $paymentLink = PaymentLink::query()->firstOrFail();

    expect($paymentLink->team_id)->toBe($team->id)
        ->and($paymentLink->product_id)->toBe($product->id)
        ->and($paymentLink->price_id)->toBeNull()
        ->and($paymentLink->trial_offer_id)->toBe($trialOffer->id)
        ->and($paymentLink->url())->toBe(route('hosted.payment-links.show', $paymentLink->public_id));

    $this->actingAs($owner)
        ->post(route('catalog.trials.payment-link', [$product, $trialOffer]))
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

test('catalog trial offers include an existing hosted trial link', function () {
    ['owner' => $owner, 'team' => $team, 'product' => $product, 'price' => $transitionPrice] = invoiceFixture();
    $trialPrice = Price::factory()->for($team)->for($product)->free()->create([
        'currency' => $transitionPrice->currency,
        'billing_interval' => 'day',
        'billing_frequency' => 14,
    ]);
    $trialOffer = TrialOffer::factory()->create([
        'team_id' => $team->id,
        'product_id' => $product->id,
        'trial_price_id' => $trialPrice->id,
        'transition_product_id' => $product->id,
        'transition_price_id' => $transitionPrice->id,
    ]);
    $paymentLink = PaymentLink::query()->create([
        'team_id' => $team->id,
        'product_id' => $product->id,
        'trial_offer_id' => $trialOffer->id,
    ]);

    $this->actingAs($owner)
        ->get(route('catalog.products.show', $product))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('catalog/show')
            ->where('trials.0.paymentLink.id', $paymentLink->public_id)
            ->where('trials.0.paymentLink.url', $paymentLink->url())
            ->where('trials.0.paymentLink.priceLabel', $trialOffer->name)
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

test('a hosted trial link starts a trialing subscription without checkout or invoice', function () {
    ['team' => $team, 'product' => $product, 'price' => $transitionPrice] = invoiceFixture();
    Http::fake();
    $trialPrice = Price::factory()->for($team)->for($product)->free()->create([
        'currency' => $transitionPrice->currency,
        'billing_interval' => 'day',
        'billing_frequency' => 14,
    ]);
    $trialOffer = TrialOffer::factory()->create([
        'team_id' => $team->id,
        'product_id' => $product->id,
        'trial_price_id' => $trialPrice->id,
        'transition_product_id' => $product->id,
        'transition_price_id' => $transitionPrice->id,
    ]);
    $paymentLink = PaymentLink::query()->create([
        'team_id' => $team->id,
        'product_id' => $product->id,
        'trial_offer_id' => $trialOffer->id,
    ]);

    $this->post(route('hosted.payment-links.checkout', $paymentLink->public_id), [
        'name' => 'Ada Lovelace',
        'email' => 'ADA@example.com',
    ])->assertRedirect(route('hosted.payment-links.show', [
        'publicId' => $paymentLink->public_id,
        'trial_started' => 1,
        'email' => 'ada@example.com',
    ]));

    $customer = Customer::query()->where('email', 'ada@example.com')->firstOrFail();
    $subscription = Subscription::query()->with('items.currentTrial')->firstOrFail();
    $trial = SubscriptionItemTrial::query()->firstOrFail();

    expect($customer->name)->toBe('Ada Lovelace')
        ->and($subscription->customer_id)->toBe($customer->id)
        ->and($subscription->status)->toBe(SubscriptionStatus::Trialing)
        ->and($subscription->payment_method_id)->toBeNull()
        ->and($subscription->trial_ends_at?->toDateString())->toBe(now()->addDays(14)->toDateString())
        ->and($subscription->items->first()->price_id)->toBe($trialPrice->id)
        ->and($trial->trial_offer_id)->toBe($trialOffer->id)
        ->and($trial->transition_price_id)->toBe($transitionPrice->id)
        ->and(Invoice::query()->count())->toBe(0)
        ->and(Event::query()->where('type', OutboundEventType::SubscriptionCreated)->count())->toBe(1);

    Http::assertNothingSent();
});

test('a recurring payment link stages an invoice and starts Nomba checkout without creating a subscription', function () {
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
    $invoice = $customer->invoices()->with('lines')->firstOrFail();

    expect($customer->subscriptions()->count())->toBe(0)
        ->and(Subscription::query()->count())->toBe(0)
        ->and($invoice->subscription_id)->toBeNull()
        ->and($invoice->lines->first()->price_id)->toBe($price->id)
        ->and($invoice->lines->first()->total)->toBe($price->unit_amount)
        ->and($invoice->custom_data['checkout_link'])->toBe('https://checkout.nomba.com/pay/recurring-link')
        ->and($invoice->custom_data['pending_subscription']['payment_link_id'])->toBe($paymentLink->public_id)
        ->and($invoice->custom_data['pending_subscription']['price_id'])->toBe($price->id)
        ->and(Event::query()->where('type', OutboundEventType::SubscriptionCreated)->count())->toBe(0);

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

test('a recurring payment link creates the subscription only after hosted payment succeeds', function () {
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
    ])->assertRedirect('https://checkout.nomba.com/pay/recurring-link');

    expect(Subscription::query()->count())->toBe(0);

    $invoice = Invoice::query()->firstOrFail();
    $orderReference = (string) $invoice->custom_data['checkout_order_reference'];

    $this->get(route('hosted.checkout.callback', ['orderReference' => $orderReference]))
        ->assertRedirect(route('hosted.invoices.show', $invoice->public_id));

    $subscription = Subscription::query()->with(['items', 'paymentMethod'])->firstOrFail();
    $invoice->refresh()->load('payments');

    expect($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->items->first()->price_id)->toBe($price->id)
        ->and($subscription->payment_method_id)->not->toBeNull()
        ->and($invoice->subscription_id)->toBe($subscription->id)
        ->and($invoice->status)->toBe(InvoiceStatus::Paid)
        ->and($invoice->payments->first()->status)->toBe(PaymentStatus::Succeeded)
        ->and($invoice->custom_data)->not->toHaveKey('pending_subscription')
        ->and(Event::query()->where('type', OutboundEventType::SubscriptionCreated)->count())->toBe(1);
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
