<?php

use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Models\TeamProcessorConnection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    config([
        'services.nomba.hackathon_ingress.enabled' => true,
        'services.nomba.hackathon_ingress.path' => 'ingres/qydaD5iz2W0V2bRPTaqlTJYVaiR2zLAd',
    ]);
});

test('the hackathon ingress route is disabled unless configured', function () {
    config(['services.nomba.hackathon_ingress.enabled' => false]);

    $this->postJson('/ingres/qydaD5iz2W0V2bRPTaqlTJYVaiR2zLAd', [])
        ->assertNotFound();
});

test('the hackathon ingress resolves the team from the payload account id and settles payment', function () {
    Mail::fake();

    ['owner' => $owner, 'team' => $team, 'customer' => $customer, 'price' => $price] = subscriptionFixture();
    $connection = TeamProcessorConnection::factory()->for($team)->testConnected()->create([
        'nomba_test_webhook_secret' => 'whsec_hackathon',
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

    postSignedNombaWebhookAt(
        '/ingres/qydaD5iz2W0V2bRPTaqlTJYVaiR2zLAd',
        $payload,
        'whsec_hackathon',
    )->assertOk()->assertJson(['received' => true]);

    $invoice->refresh();
    $subscription->refresh();

    expect($invoice->status)->toBe(InvoiceStatus::Paid)
        ->and($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($invoice->payments()->firstOrFail()->status)->toBe(PaymentStatus::Succeeded);
});

test('the hackathon ingress can fall back to a configured team id', function () {
    Mail::fake();

    ['owner' => $owner, 'team' => $team, 'customer' => $customer, 'price' => $price] = subscriptionFixture();
    $connection = TeamProcessorConnection::factory()->for($team)->testConnected()->create([
        'nomba_test_webhook_secret' => 'whsec_hackathon',
    ]);
    config(['services.nomba.hackathon_ingress.fallback_team_id' => $team->id]);
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

    $payload = nombaPaymentSuccessPayload($orderReference, 'unknown-account-id');

    postSignedNombaWebhookAt(
        '/ingres/qydaD5iz2W0V2bRPTaqlTJYVaiR2zLAd',
        $payload,
        'whsec_hackathon',
    )->assertOk();

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and($connection->fresh()->webhook_verified_at)->not->toBeNull();
});

test('the hackathon ingress returns not found when no connection matches', function () {
    $payload = nombaPaymentSuccessPayload('order-ref', 'does-not-exist');

    postSignedNombaWebhookAt(
        '/ingres/qydaD5iz2W0V2bRPTaqlTJYVaiR2zLAd',
        $payload,
        'whsec_hackathon',
    )->assertNotFound();
});
