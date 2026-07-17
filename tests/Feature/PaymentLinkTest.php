<?php

use App\Enums\InvoiceStatus;
use App\Enums\OutboundEventType;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\TrialUnit;
use App\Models\Customer;
use App\Models\Event;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentLink;
use App\Models\Price;
use App\Models\Subscription;
use App\Models\TeamProcessorConnection;
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

test('the hosted payment link page surfaces the product return link', function () {
    ['team' => $team, 'product' => $product, 'price' => $price] = invoiceFixture();

    $product->update(['website_url' => 'https://acme.example.com/app']);

    $paymentLink = PaymentLink::query()->create([
        'team_id' => $team->id,
        'product_id' => $product->id,
        'price_id' => $price->id,
    ]);

    $this->get(route('hosted.payment-links.show', $paymentLink->public_id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('hosted/payment-link')
            ->where('paymentLink.returnUrl', 'https://acme.example.com/app'),
        );
});

test('the hosted payment link page has no return link when the product has none set', function () {
    ['team' => $team, 'product' => $product, 'price' => $price] = invoiceFixture();

    $paymentLink = PaymentLink::query()->create([
        'team_id' => $team->id,
        'product_id' => $product->id,
        'price_id' => $price->id,
    ]);

    $this->get(route('hosted.payment-links.show', $paymentLink->public_id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('hosted/payment-link')
            ->where('paymentLink.returnUrl', null),
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

test('a payment link on a free-trial price starts the trial and charges nothing', function () {
    ['team' => $team, 'product' => $product, 'price' => $price] = invoiceFixture();
    TeamProcessorConnection::factory()->for($team)->testConnected()->create();
    fakeNombaCheckout('https://checkout.nomba.com/pay/should-not-be-used');

    $price->forceFill([
        'unit_amount' => 2000000,
        'trial_length' => 7,
        'trial_unit' => TrialUnit::Day,
        'trial_requires_payment_info' => false,
    ])->save();

    $paymentLink = PaymentLink::query()->create([
        'team_id' => $team->id,
        'product_id' => $product->id,
        'price_id' => $price->id,
    ]);

    $this->from(route('hosted.payment-links.show', $paymentLink->public_id))
        ->post(route('hosted.payment-links.checkout', $paymentLink->public_id), [
            'name' => 'Ada Lovelace',
            'email' => 'ADA@example.com',
        ])
        ->assertRedirect(route('hosted.payment-links.show', $paymentLink->public_id))
        ->assertSessionHas('checkoutSuccess');

    $subscription = Subscription::query()->with('items')->firstOrFail();

    // The bug: day 0 billed the full ₦20,000 on a price sold as a free trial.
    expect(Invoice::query()->count())->toBe(0)
        ->and(Payment::query()->count())->toBe(0)
        ->and($subscription->status)->toBe(SubscriptionStatus::Trialing)
        ->and($subscription->trial_ends_at?->toDateString())
        ->toBe(now()->addDays(7)->toDateString())
        ->and($subscription->items->first()->price_id)->toBe($price->id)
        ->and($subscription->custom_data['payment_link_id'])->toBe($paymentLink->public_id)
        ->and(Event::query()->where('type', OutboundEventType::SubscriptionCreated)->count())->toBe(1);

    // No money leg at all — the customer never reaches a gateway.
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/v1/checkout/order'));
});

test('the hosted page confirms a started trial and names the date billing begins', function () {
    ['team' => $team, 'product' => $product, 'price' => $price] = invoiceFixture();
    TeamProcessorConnection::factory()->for($team)->testConnected()->create();
    fakeNombaCheckout('https://checkout.nomba.com/pay/should-not-be-used');

    $price->forceFill([
        'trial_length' => 7,
        'trial_unit' => TrialUnit::Day,
        'trial_requires_payment_info' => false,
    ])->save();

    $paymentLink = PaymentLink::query()->create([
        'team_id' => $team->id,
        'product_id' => $product->id,
        'price_id' => $price->id,
    ]);

    $this->from(route('hosted.payment-links.show', $paymentLink->public_id))
        ->post(route('hosted.payment-links.checkout', $paymentLink->public_id), [
            'email' => 'ada@example.com',
        ]);

    $this->followingRedirects()
        ->get(route('hosted.payment-links.show', $paymentLink->public_id))
        ->assertInertia(fn (Assert $page) => $page
            ->where('checkoutSuccess', 'Your free trial has started — you have not been charged. Billing begins on '.now()->addDays(7)->format('M j, Y').', and you can cancel any time before then.')
        );
});

test('a free-trial payment link writes the trial redemption so it cannot be reused', function () {
    ['team' => $team, 'product' => $product, 'price' => $price] = invoiceFixture();
    TeamProcessorConnection::factory()->for($team)->testConnected()->create();
    fakeNombaCheckout('https://checkout.nomba.com/pay/should-not-be-used');

    $price->forceFill([
        'trial_length' => 7,
        'trial_unit' => TrialUnit::Day,
        'trial_requires_payment_info' => false,
        'trial_once_per_customer' => true,
    ])->save();

    $paymentLink = PaymentLink::query()->create([
        'team_id' => $team->id,
        'product_id' => $product->id,
        'price_id' => $price->id,
    ]);

    $this->from(route('hosted.payment-links.show', $paymentLink->public_id))
        ->post(route('hosted.payment-links.checkout', $paymentLink->public_id), [
            'email' => 'ada@example.com',
        ])->assertSessionHas('checkoutSuccess');

    expect($team->priceTrialRedemptions()->count())->toBe(1);

    // Same customer, same link, second time — `trial_once_per_customer` holds
    // on the link path because it routes through CreateSubscription.
    $this->post(route('hosted.payment-links.checkout', $paymentLink->public_id), [
        'email' => 'ada@example.com',
    ])->assertSessionHas('checkoutError');

    expect(Subscription::query()->count())->toBe(1);
});

test('a payment link on a trial that requires card details is refused, not charged', function () {
    ['team' => $team, 'product' => $product, 'price' => $price] = invoiceFixture();
    TeamProcessorConnection::factory()->for($team)->testConnected()->create();
    fakeNombaCheckout('https://checkout.nomba.com/pay/should-not-be-used');

    $price->forceFill([
        'unit_amount' => 2000000,
        'trial_length' => 7,
        'trial_unit' => TrialUnit::Day,
        'trial_requires_payment_info' => true,
    ])->save();

    $paymentLink = PaymentLink::query()->create([
        'team_id' => $team->id,
        'product_id' => $product->id,
        'price_id' => $price->id,
    ]);

    $this->post(route('hosted.payment-links.checkout', $paymentLink->public_id), [
        'email' => 'ada@example.com',
    ])->assertSessionHas('checkoutError');

    // No gateway can tokenise without charging, so the only wrong outcome here
    // is taking the customer's ₦20,000 for a "free" trial.
    expect(Invoice::query()->count())->toBe(0)
        ->and(Payment::query()->count())->toBe(0)
        ->and(Subscription::query()->count())->toBe(0);

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/v1/checkout/order'));
});

test('a payment link on a price with no trial still charges the full price', function () {
    ['team' => $team, 'product' => $product, 'price' => $price] = invoiceFixture();
    TeamProcessorConnection::factory()->for($team)->testConnected()->create();
    fakeNombaCheckout('https://checkout.nomba.com/pay/no-trial');

    $paymentLink = PaymentLink::query()->create([
        'team_id' => $team->id,
        'product_id' => $product->id,
        'price_id' => $price->id,
    ]);

    $this->post(route('hosted.payment-links.checkout', $paymentLink->public_id), [
        'email' => 'ada@example.com',
    ])->assertRedirect('https://checkout.nomba.com/pay/no-trial');

    expect(Invoice::query()->firstOrFail()->total)->toBe($price->unit_amount)
        ->and(Subscription::query()->count())->toBe(0);
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
