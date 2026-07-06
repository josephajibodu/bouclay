<?php

use App\Enums\InvoiceBillingReason;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionItemStatus;
use App\Enums\SubscriptionStatus;
use App\Models\PaymentMethod;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\TeamProcessorConnection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

test('a payment_success webhook marks the invoice paid and activates the subscription without a redirect', function () {
    Mail::fake();

    ['owner' => $owner, 'team' => $team, 'customer' => $customer, 'price' => $price] = subscriptionFixture();
    $connection = TeamProcessorConnection::factory()->for($team)->testConnected()->create([
        'nomba_test_webhook_secret' => 'whsec_test_secret',
    ]);
    fakeNombaCheckout();

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

    $payload = nombaPaymentSuccessPayload($orderReference, $connection->nomba_test_account_id);

    postSignedNombaWebhook($connection, $payload, 'whsec_test_secret')
        ->assertOk()
        ->assertJson(['received' => true]);

    $invoice->refresh();
    $subscription->refresh();

    expect($invoice->status)->toBe(InvoiceStatus::Paid)
        ->and($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($invoice->payments()->firstOrFail()->status)->toBe(PaymentStatus::Succeeded)
        ->and($customer->paymentMethods()->count())->toBe(1)
        ->and(Cache::missing("nomba_checkout:{$orderReference}"))->toBeTrue()
        ->and(Cache::missing("nomba_token:{$orderReference}"))->toBeTrue();
});

test('duplicate payment_success webhooks are idempotent', function () {
    Mail::fake();

    ['owner' => $owner, 'team' => $team, 'customer' => $customer, 'price' => $price] = subscriptionFixture();
    $connection = TeamProcessorConnection::factory()->for($team)->testConnected()->create();
    fakeNombaCheckout();

    $this->actingAs($owner)
        ->post(route('subscriptions.store'), [
            'customer_id' => $customer->id,
            'collection_mode' => 'automatic',
            'items' => [['kind' => 'price', 'price_id' => $price->id]],
        ]);

    $invoice = Subscription::query()->firstOrFail()->invoices()->firstOrFail();
    $orderReference = (string) $invoice->custom_data['checkout_order_reference'];

    Cache::put("nomba_checkout:{$orderReference}", [
        'invoice_id' => $invoice->id,
        'customer_id' => $customer->id,
        'team_id' => $team->id,
        'mode' => 'test',
        'tokenize_card' => true,
        'set_default' => true,
    ], now()->addHour());

    $payload = nombaPaymentSuccessPayload($orderReference, $connection->nomba_test_account_id);

    postSignedNombaWebhook($connection, $payload)->assertOk();
    postSignedNombaWebhook($connection, $payload)->assertOk();

    expect($invoice->fresh()->payments()->count())->toBe(1);
});

test('a payment_failed webhook on a renewal invoice moves an active subscription to past due', function () {
    Mail::fake();

    ['team' => $team, 'customer' => $customer, 'price' => $price] = subscriptionFixture();
    $card = PaymentMethod::factory()->for($team)->for($customer)->create();
    $connection = TeamProcessorConnection::factory()->for($team)->testConnected()->create();

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

    $invoice = $subscription->invoices()->create([
        'team_id' => $team->id,
        'customer_id' => $customer->id,
        'number' => 'BCL-9001',
        'status' => InvoiceStatus::Open,
        'billing_reason' => InvoiceBillingReason::SubscriptionCycle,
        'collection_mode' => 'automatic',
        'currency' => 'NGN',
        'subtotal' => 500000,
        'discount_total' => 0,
        'tax_total' => 0,
        'total' => 500000,
        'amount_paid' => 0,
        'amount_due' => 500000,
        'finalized_at' => now(),
    ]);

    $orderReference = (string) Str::uuid();

    $invoice->payments()->create([
        'team_id' => $team->id,
        'customer_id' => $customer->id,
        'payment_method_id' => $card->id,
        'processor' => 'nomba',
        'processor_reference' => $orderReference,
        'amount' => $invoice->total,
        'currency' => 'NGN',
        'status' => PaymentStatus::Failed,
        'failure_reason' => 'Insufficient funds',
        'attempt_number' => 1,
        'idempotency_key' => hash('sha256', "invoice:{$invoice->id}:attempt:1"),
    ]);

    $payload = [
        'event_type' => 'payment_failed',
        'requestId' => (string) Str::uuid(),
        'data' => [
            'merchant' => ['userId' => $connection->nomba_test_account_id],
            'transaction' => [
                'transactionId' => 'WEB-failed-1',
                'type' => 'online_checkout',
                'time' => now()->toIso8601String(),
                'responseMessage' => 'Insufficient funds',
            ],
            'order' => [
                'orderReference' => $orderReference,
                'accountId' => $connection->nomba_test_account_id,
            ],
        ],
    ];

    postSignedNombaWebhook($connection, $payload)->assertOk();

    $subscription->refresh();

    expect($subscription->status)->toBe(SubscriptionStatus::PastDue)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Open);
});

test('webhooks with a malformed timestamp are rejected without a server error', function () {
    $team = Team::factory()->create();
    $connection = TeamProcessorConnection::factory()->for($team)->testConnected()->create([
        'nomba_test_webhook_secret' => 'whsec_test_secret',
    ]);

    $payload = nombaPaymentSuccessPayload('order-ref', $connection->nomba_test_account_id);

    $this->postJson("/webhooks/nomba/{$connection->inbound_webhook_token}", $payload, [
        'nomba-signature' => nombaWebhookSignature($payload, 'whsec_test_secret', 'not-a-valid-timestamp'),
        'nomba-timestamp' => 'not-a-valid-timestamp',
    ])->assertUnauthorized();
});

test('webhooks with an invalid signature are rejected', function () {
    $team = Team::factory()->create();
    $connection = TeamProcessorConnection::factory()->for($team)->testConnected()->create([
        'nomba_test_webhook_secret' => 'whsec_test_secret',
    ]);

    $payload = nombaPaymentSuccessPayload('order-ref', $connection->nomba_test_account_id);

    $this->postJson("/webhooks/nomba/{$connection->inbound_webhook_token}", $payload, [
        'nomba-signature' => 'invalid-signature',
        'nomba-timestamp' => now()->toIso8601String(),
    ])->assertUnauthorized();
});

test('webhooks are rejected when no signing secret is configured for the active mode', function () {
    $team = Team::factory()->create();
    $connection = TeamProcessorConnection::factory()->for($team)->testConnected()->create([
        'nomba_test_webhook_secret' => null,
    ]);

    $payload = nombaPaymentSuccessPayload('order-ref', $connection->nomba_test_account_id);

    postSignedNombaWebhook($connection, $payload, 'whsec_test_secret')
        ->assertUnauthorized();
});

test('the public inbound endpoint still marks the connection reachable for synthetic test events', function () {
    $team = Team::factory()->create();
    $connection = TeamProcessorConnection::factory()->for($team)->testConnected()->create([
        'nomba_test_webhook_secret' => 'whsec_test_secret',
    ]);

    expect($connection->webhook_verified_at)->toBeNull();

    $this->postJson("/webhooks/nomba/{$connection->inbound_webhook_token}", [
        'event_type' => 'bouclay.test_event',
    ])->assertOk()->assertJson(['received' => true]);

    expect($connection->refresh()->webhook_verified_at)->not->toBeNull();
});

test('payment_success webhooks still stash tokenized card data by order reference', function () {
    $team = Team::factory()->create();
    $connection = TeamProcessorConnection::factory()->for($team)->testConnected()->create();
    $orderReference = 'order-token-stash';

    $payload = nombaPaymentSuccessPayload($orderReference, $connection->nomba_test_account_id);

    postSignedNombaWebhook($connection, $payload)->assertOk();

    $cached = Cache::get("nomba_token:{$orderReference}");

    expect($cached)->toBeArray()
        ->and($cached['tokenKey'])->toBe('tok_webhook_test')
        ->and($cached['brand'])->toBe('Visa');
});
