<?php

use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionItemStatus;
use App\Enums\SubscriptionStatus;
use App\Mail\InvoiceIssued;
use App\Models\PaymentMethod;
use App\Models\Subscription;
use App\Models\TeamProcessorConnection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;

test('the hosted invoice page renders for a public invoice id', function () {
    ['owner' => $owner, 'customer' => $customer, 'price' => $price] = invoiceFixture();

    Mail::fake();

    $this->actingAs($owner)
        ->post(route('invoices.store'), [
            'customer_id' => $customer->id,
            'collection_mode' => 'manual',
            'items' => [['price_id' => $price->id]],
        ]);

    $invoice = $customer->invoices()->firstOrFail();

    $this->get(route('hosted.invoices.show', $invoice->public_id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('hosted/invoice')
            ->where('invoice.publicId', $invoice->public_id)
            ->where('invoice.canPay', true));
});

test('creating a manual invoice queues the initial action email with the hosted link', function () {
    Mail::fake();

    ['owner' => $owner, 'customer' => $customer, 'price' => $price] = invoiceFixture();

    $this->actingAs($owner)
        ->post(route('invoices.store'), [
            'customer_id' => $customer->id,
            'collection_mode' => 'manual',
            'items' => [['price_id' => $price->id]],
        ]);

    $invoice = $customer->invoices()->firstOrFail();

    Mail::assertQueued(InvoiceIssued::class, function (InvoiceIssued $mail) use ($invoice): bool {
        return $mail->invoice->is($invoice)
            && str_contains($mail->actionUrl, $invoice->public_id)
            && $mail->actionLabel === 'View and pay invoice';
    });
});

test('creating an automatic invoice without a card generates checkout and queues email', function () {
    Mail::fake();

    ['owner' => $owner, 'team' => $team, 'customer' => $customer, 'price' => $price] = invoiceFixture();
    TeamProcessorConnection::factory()->for($team)->testConnected()->create();
    fakeNombaCheckout();

    $this->actingAs($owner)
        ->post(route('invoices.store'), [
            'customer_id' => $customer->id,
            'collection_mode' => 'automatic',
            'items' => [['price_id' => $price->id]],
        ]);

    $invoice = $customer->invoices()->firstOrFail();

    expect($invoice->custom_data['checkout_link'] ?? null)->toStartWith('https://');

    Mail::assertQueued(InvoiceIssued::class, function (InvoiceIssued $mail): bool {
        return $mail->actionLabel === 'Pay now'
            && str_contains($mail->actionUrl, 'checkout.nomba.com');
    });
});

test('hosted checkout completion marks the invoice paid and activates the subscription', function () {
    ['owner' => $owner, 'team' => $team, 'customer' => $customer, 'price' => $price] = subscriptionFixture();
    TeamProcessorConnection::factory()->for($team)->testConnected()->create();
    fakeNombaCheckout();

    Mail::fake();

    $this->actingAs($owner)
        ->post(route('subscriptions.store'), [
            'customer_id' => $customer->id,
            'collection_mode' => 'automatic',
            'items' => [['kind' => 'price', 'price_id' => $price->id]],
        ]);

    $subscription = Subscription::query()->firstOrFail();
    $invoice = $subscription->invoices()->firstOrFail();
    $orderReference = (string) $invoice->custom_data['checkout_order_reference'];

    Cache::put("nomba_checkout:{$orderReference}", [
        'invoice_id' => $invoice->id,
        'customer_id' => $customer->id,
        'team_id' => $team->id,
        'mode' => 'test',
        'tokenize_card' => true,
        'set_default' => true,
    ], now()->addHour());

    $this->get(route('hosted.checkout.callback', ['orderReference' => $orderReference]))
        ->assertRedirect(route('hosted.invoices.show', $invoice->public_id));

    $invoice->refresh();
    $subscription->refresh();

    expect($invoice->status)->toBe(InvoiceStatus::Paid)
        ->and($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($invoice->payments()->firstOrFail()->status)->toBe(PaymentStatus::Succeeded)
        ->and($customer->paymentMethods()->count())->toBe(1);
});

test('the renewal command generates a new invoice and collects it', function () {
    Mail::fake();

    ['owner' => $owner, 'team' => $team, 'customer' => $customer, 'price' => $price] = subscriptionFixture();
    $card = PaymentMethod::factory()->for($team)->for($customer)->create();
    TeamProcessorConnection::factory()->for($team)->testConnected()->create();
    fakeNombaCharge();

    $subscription = Subscription::factory()
        ->for($team)
        ->for($customer)
        ->create([
            'collection_mode' => 'automatic',
            'payment_method_id' => $card->id,
            'status' => SubscriptionStatus::Active,
            'current_period_start' => now()->subMonth(),
            'current_period_end' => now()->subDay(),
        ]);

    $subscription->items()->create([
        'price_id' => $price->id,
        'product_id' => $price->product_id,
        'quantity' => 1,
        'status' => SubscriptionItemStatus::Active,
    ]);

    $this->artisan('subscriptions:bill-renewals')->assertSuccessful();

    $invoice = $subscription->fresh()->invoices()->latest('id')->firstOrFail();

    expect($invoice->billing_reason->value)->toBe('subscription_cycle')
        ->and($invoice->status)->toBe(InvoiceStatus::Paid)
        ->and($subscription->fresh()->current_period_end)->not->toBeNull()
        ->and($subscription->fresh()->current_period_end->isFuture())->toBeTrue();
});

test('a declined renewal charge moves an active subscription to past due', function () {
    Mail::fake();

    ['team' => $team, 'customer' => $customer, 'price' => $price] = subscriptionFixture();
    $card = PaymentMethod::factory()->for($team)->for($customer)->create();
    TeamProcessorConnection::factory()->for($team)->testConnected()->create();
    fakeNombaCharge(approved: false);

    $subscription = Subscription::factory()
        ->for($team)
        ->for($customer)
        ->create([
            'collection_mode' => 'automatic',
            'payment_method_id' => $card->id,
            'status' => SubscriptionStatus::Active,
            'current_period_start' => now()->subMonth(),
            'current_period_end' => now()->subDay(),
        ]);

    $subscription->items()->create([
        'price_id' => $price->id,
        'product_id' => $price->product_id,
        'quantity' => 1,
        'status' => SubscriptionItemStatus::Active,
    ]);

    $this->artisan('subscriptions:bill-renewals')->assertSuccessful();

    $subscription->refresh();
    $invoice = $subscription->invoices()->latest('id')->firstOrFail();

    expect($subscription->status)->toBe(SubscriptionStatus::PastDue)
        ->and($invoice->status)->toBe(InvoiceStatus::Open)
        ->and($invoice->payments()->firstOrFail()->status)->toBe(PaymentStatus::Failed);
});

test('hosted invoice pay redirects to nomba checkout', function () {
    ['owner' => $owner, 'team' => $team, 'customer' => $customer, 'price' => $price] = invoiceFixture();
    TeamProcessorConnection::factory()->for($team)->testConnected()->create();
    fakeNombaCheckout('https://checkout.nomba.com/pay/test-hosted');

    Mail::fake();

    $this->actingAs($owner)
        ->post(route('invoices.store'), [
            'customer_id' => $customer->id,
            'collection_mode' => 'manual',
            'items' => [['price_id' => $price->id]],
        ]);

    $invoice = $customer->invoices()->firstOrFail();

    $this->post(route('hosted.invoices.pay', $invoice->public_id))
        ->assertRedirect('https://checkout.nomba.com/pay/test-hosted');
});

test('a duplicate hosted checkout callback is idempotent', function () {
    ['owner' => $owner, 'team' => $team, 'customer' => $customer, 'price' => $price] = subscriptionFixture();
    TeamProcessorConnection::factory()->for($team)->testConnected()->create();
    fakeNombaCheckout();

    Mail::fake();

    $this->actingAs($owner)
        ->post(route('subscriptions.store'), [
            'customer_id' => $customer->id,
            'collection_mode' => 'automatic',
            'items' => [['kind' => 'price', 'price_id' => $price->id]],
        ]);

    $subscription = Subscription::query()->firstOrFail();
    $invoice = $subscription->invoices()->firstOrFail();
    $orderReference = (string) $invoice->custom_data['checkout_order_reference'];

    Cache::put("nomba_checkout:{$orderReference}", [
        'invoice_id' => $invoice->id,
        'customer_id' => $customer->id,
        'team_id' => $team->id,
        'mode' => 'test',
        'tokenize_card' => true,
        'set_default' => true,
    ], now()->addHour());

    $callback = route('hosted.checkout.callback', ['orderReference' => $orderReference]);

    $this->get($callback)->assertRedirect();
    $this->get($callback)->assertRedirect();

    expect($invoice->fresh()->payments()->count())->toBe(1)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Paid);
});

test('automatic collection without nomba connected records a collection error instead of failing silently', function () {
    Mail::fake();

    ['owner' => $owner, 'customer' => $customer, 'price' => $price] = invoiceFixture();

    $this->actingAs($owner)
        ->post(route('invoices.store'), [
            'customer_id' => $customer->id,
            'collection_mode' => 'automatic',
            'items' => [['price_id' => $price->id]],
        ]);

    $invoice = $customer->invoices()->firstOrFail();

    expect($invoice->custom_data['collection_error'] ?? null)->not->toBeNull();
    Mail::assertNothingQueued();
});
