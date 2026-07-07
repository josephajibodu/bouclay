<?php

use App\Actions\Subscriptions\UpdateSubscriptionItem;
use App\Enums\InvoiceBillingReason;
use App\Enums\InvoiceLineKind;
use App\Enums\InvoiceStatus;
use App\Enums\SubscriptionItemStatus;
use App\Enums\SubscriptionStatus;
use App\Models\PaymentMethod;
use App\Models\Price;
use App\Models\Subscription;
use App\Models\TeamProcessorConnection;

test('increasing quantity mid-cycle creates proration invoice lines', function () {
    ['team' => $team, 'customer' => $customer, 'price' => $price] = subscriptionFixture();

    $periodStart = now()->subDays(10);
    $periodEnd = now()->addDays(20);

    $subscription = Subscription::factory()
        ->for($team)
        ->for($customer)
        ->manual()
        ->create([
            'status' => SubscriptionStatus::Active,
            'current_period_start' => $periodStart,
            'current_period_end' => $periodEnd,
        ]);

    $item = $subscription->items()->create([
        'price_id' => $price->id,
        'product_id' => $price->product_id,
        'quantity' => 1,
        'status' => SubscriptionItemStatus::Active,
    ]);

    app(UpdateSubscriptionItem::class)->handle(
        subscription: $subscription,
        item: $item,
        quantity: 3,
    );

    $item->refresh();
    $invoice = $subscription->fresh()->invoices()->firstOrFail();

    expect($item->quantity)->toBe(3)
        ->and($invoice->billing_reason)->toBe(InvoiceBillingReason::SubscriptionUpdate)
        ->and($invoice->status)->toBe(InvoiceStatus::Open)
        ->and($invoice->lines)->toHaveCount(2)
        ->and($invoice->lines->every(fn ($line) => $line->kind === InvoiceLineKind::Proration))->toBeTrue()
        ->and($invoice->lines->every(fn ($line) => $line->proration))->toBeTrue()
        ->and($invoice->total)->toBeGreaterThan(0);
});

test('changing plan mid-cycle creates credit and charge proration lines', function () {
    ['team' => $team, 'customer' => $customer, 'product' => $product, 'price' => $oldPrice] = subscriptionFixture();

    $newPrice = Price::factory()->for($team)->for($product)->create([
        'unit_amount' => 8_000_000,
        'currency' => 'NGN',
    ]);

    $subscription = Subscription::factory()
        ->for($team)
        ->for($customer)
        ->manual()
        ->create([
            'status' => SubscriptionStatus::Active,
            'current_period_start' => now()->subDays(15),
            'current_period_end' => now()->addDays(15),
        ]);

    $item = $subscription->items()->create([
        'price_id' => $oldPrice->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'status' => SubscriptionItemStatus::Active,
    ]);

    app(UpdateSubscriptionItem::class)->handle(
        subscription: $subscription,
        item: $item,
        priceId: $newPrice->id,
    );

    $item->refresh();
    $invoice = $subscription->fresh()->invoices()->firstOrFail();

    expect($item->price_id)->toBe($newPrice->id)
        ->and($invoice->lines)->toHaveCount(2)
        ->and($invoice->lines->contains(fn ($line) => $line->unit_amount < 0))->toBeTrue()
        ->and($invoice->lines->contains(fn ($line) => $line->unit_amount > 0))->toBeTrue();
});

test('an automatic proration update charges the customer when a card is on file', function () {
    ['team' => $team, 'customer' => $customer, 'price' => $price] = subscriptionFixture();
    $card = PaymentMethod::factory()->for($team)->for($customer)->create();
    TeamProcessorConnection::factory()->for($team)->testConnected()->create();
    fakeNombaCharge();

    $subscription = Subscription::factory()
        ->for($team)
        ->for($customer)
        ->create([
            'payment_method_id' => $card->id,
            'status' => SubscriptionStatus::Active,
            'current_period_start' => now()->subDays(10),
            'current_period_end' => now()->addDays(20),
        ]);

    $item = $subscription->items()->create([
        'price_id' => $price->id,
        'product_id' => $price->product_id,
        'quantity' => 1,
        'status' => SubscriptionItemStatus::Active,
    ]);

    app(UpdateSubscriptionItem::class)->handle(
        subscription: $subscription,
        item: $item,
        quantity: 2,
    );

    $invoice = $subscription->fresh()->invoices()->firstOrFail();

    expect($invoice->status)->toBe(InvoiceStatus::Paid)
        ->and($invoice->payments()->exists())->toBeTrue();
});

test('updating a subscription item through the dashboard route validates ownership', function () {
    ['owner' => $owner, 'team' => $team, 'customer' => $customer, 'price' => $price] = subscriptionFixture();

    $subscription = Subscription::factory()
        ->for($team)
        ->for($customer)
        ->create([
            'status' => SubscriptionStatus::Active,
            'current_period_start' => now()->subDays(5),
            'current_period_end' => now()->addDays(25),
        ]);

    $item = $subscription->items()->create([
        'price_id' => $price->id,
        'product_id' => $price->product_id,
        'quantity' => 1,
        'status' => SubscriptionItemStatus::Active,
    ]);

    $this->actingAs($owner)
        ->post(route('subscriptions.items.update', [$subscription, $item]), [
            'quantity' => 2,
        ])
        ->assertRedirect();

    expect($item->fresh()->quantity)->toBe(2);
});
