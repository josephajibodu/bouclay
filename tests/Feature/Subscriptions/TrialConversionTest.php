<?php

use App\Enums\InvoiceBillingReason;
use App\Enums\InvoiceStatus;
use App\Enums\SubscriptionItemStatus;
use App\Enums\SubscriptionItemTrialStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\TrialEndBehavior;
use App\Models\PaymentMethod;
use App\Models\Price;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\SubscriptionItemTrial;
use App\Models\TeamProcessorConnection;
use App\Models\TrialOffer;

test('the trial conversion command bills a free trial when it ends', function () {
    ['owner' => $owner, 'team' => $team, 'customer' => $customer] = subscriptionFixture();
    $offer = TrialOffer::factory()->create(['team_id' => $team->id]);

    $this->travelTo(now()->subDays(15));

    $this->actingAs($owner)
        ->post(route('subscriptions.store'), [
            'customer_id' => $customer->id,
            'collection_mode' => 'manual',
            'items' => [['kind' => 'trial', 'trial_offer_id' => $offer->id]],
        ])
        ->assertRedirect();

    $this->travelBack();

    $subscription = Subscription::query()->firstOrFail();
    $transitionPriceId = $subscription->items->first()->currentTrial->transition_price_id;

    $this->artisan('subscriptions:convert-trials')->assertSuccessful();

    $subscription->refresh();
    $item = $subscription->items->first();

    expect($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->trial_ends_at)->toBeNull()
        ->and($item->price_id)->toBe($transitionPriceId)
        ->and($item->currentTrial)->toBeNull()
        ->and($subscription->invoices()->count())->toBe(1);

    $invoice = $subscription->invoices()->firstOrFail();

    expect($invoice->status)->toBe(InvoiceStatus::Open)
        ->and($invoice->billing_reason)->toBe(InvoiceBillingReason::SubscriptionCreate);
});

test('the trial conversion command cancels a free trial when configured to cancel without a card', function () {
    ['owner' => $owner, 'team' => $team, 'customer' => $customer] = subscriptionFixture();
    $offer = TrialOffer::factory()->create(['team_id' => $team->id]);

    $this->travelTo(now()->subDays(15));

    $this->actingAs($owner)
        ->post(route('subscriptions.store'), [
            'customer_id' => $customer->id,
            'collection_mode' => 'automatic',
            'trial_end_behavior' => TrialEndBehavior::Cancel->value,
            'items' => [['kind' => 'trial', 'trial_offer_id' => $offer->id]],
        ])
        ->assertRedirect();

    $this->travelBack();

    $subscription = Subscription::query()->firstOrFail();

    $this->artisan('subscriptions:convert-trials')->assertSuccessful();

    $subscription->refresh();

    expect($subscription->status)->toBe(SubscriptionStatus::Canceled)
        ->and($subscription->invoices()->count())->toBe(0)
        ->and(
            SubscriptionItemTrial::query()
                ->where('subscription_item_id', $subscription->items->first()->id)
                ->first()
                ->status
        )->toBe(SubscriptionItemTrialStatus::Converted);
});

test('the trial conversion command charges an automatic free trial when a card is on file', function () {
    ['owner' => $owner, 'team' => $team, 'customer' => $customer] = subscriptionFixture();
    $offer = TrialOffer::factory()->create(['team_id' => $team->id]);
    $card = PaymentMethod::factory()->for($team)->for($customer)->create();
    TeamProcessorConnection::factory()->for($team)->testConnected()->create();
    fakeNombaCharge();

    $this->travelTo(now()->subDays(15));

    $this->actingAs($owner)
        ->post(route('subscriptions.store'), [
            'customer_id' => $customer->id,
            'collection_mode' => 'automatic',
            'payment_method_id' => $card->id,
            'items' => [['kind' => 'trial', 'trial_offer_id' => $offer->id]],
        ])
        ->assertRedirect();

    $this->travelBack();

    $subscription = Subscription::query()->firstOrFail();

    $this->artisan('subscriptions:convert-trials')->assertSuccessful();

    $subscription->refresh();
    $invoice = $subscription->invoices()->firstOrFail();

    expect($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($invoice->status)->toBe(InvoiceStatus::Paid)
        ->and($invoice->payments()->exists())->toBeTrue();
});

test('the trial conversion command swaps a paid trial item to its transition price', function () {
    ['team' => $team, 'customer' => $customer] = subscriptionFixture();

    $product = Product::factory()->for($team)->create();
    $introPrice = Price::factory()->for($team)->for($product)->create([
        'unit_amount' => 100_000,
        'currency' => 'NGN',
        'billing_interval' => 'month',
        'billing_frequency' => 1,
    ]);
    $regularPrice = Price::factory()->for($team)->for($product)->create([
        'unit_amount' => 4_000_000,
        'currency' => 'NGN',
        'billing_interval' => 'month',
        'billing_frequency' => 1,
    ]);

    $subscription = Subscription::factory()
        ->for($team)
        ->for($customer)
        ->create([
            'status' => SubscriptionStatus::Active,
            'trial_ends_at' => now()->subDay(),
            'current_period_start' => now()->subMonth(),
            'current_period_end' => now()->addDays(10),
        ]);

    $item = $subscription->items()->create([
        'price_id' => $introPrice->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'status' => SubscriptionItemStatus::Active,
    ]);

    SubscriptionItemTrial::factory()->create([
        'team_id' => $team->id,
        'subscription_item_id' => $item->id,
        'customer_id' => $customer->id,
        'trial_price_id' => $introPrice->id,
        'transition_price_id' => $regularPrice->id,
        'starts_at' => now()->subMonths(3),
        'ends_at' => now()->subDay(),
        'status' => SubscriptionItemTrialStatus::Active,
    ]);

    $this->artisan('subscriptions:convert-trials')->assertSuccessful();

    $item->refresh();
    $subscription->refresh();

    expect($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($item->price_id)->toBe($regularPrice->id)
        ->and($subscription->trial_ends_at)->toBeNull()
        ->and($subscription->invoices()->count())->toBe(0);
});
