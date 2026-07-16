<?php

use App\Enums\CollectionMode;
use App\Enums\InvoiceBillingReason;
use App\Enums\InvoiceStatus;
use App\Enums\OutboundEventType;
use App\Enums\PaymentStatus;
use App\Enums\WebhookDeliveryStatus;
use App\Models\Event;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\TeamProcessorConnection;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\Webhooks\SignOutboundWebhook;
use Illuminate\Support\Facades\Http;

test('a successful charge delivers a signed invoice.updated webhook to the integrator url', function () {
    ['owner' => $owner, 'team' => $team, 'customer' => $customer, 'price' => $price] = invoiceFixture();

    $integratorUrl = 'https://integrator.test/webhooks/bouclay';
    $generated = WebhookEndpoint::generateSigningSecret();

    WebhookEndpoint::factory()->for($team)->create([
        'url' => $integratorUrl,
        'signing_secret' => $generated['secret'],
    ]);

    $card = PaymentMethod::factory()->for($team)->for($customer)->create();
    TeamProcessorConnection::factory()->for($team)->testConnected()->create();

    Http::fake([
        '*/v1/auth/token/issue' => Http::response(['code' => '00', 'data' => ['access_token' => 'fake-token']]),
        '*/v1/checkout/tokenized-card-payment' => Http::response([
            'code' => '00',
            'data' => ['status' => true, 'message' => 'Approved by Financial Insitution'],
        ]),
        '*/v1/transactions/accounts/single*' => Http::response([
            'code' => '00',
            'data' => ['status' => 'SUCCESS'],
        ]),
        'integrator.test/*' => Http::response('ok', 200),
    ]);

    $this->actingAs($owner)
        ->post(route('invoices.store'), [
            'customer_id' => $customer->id,
            'collection_mode' => 'automatic',
            'payment_method_id' => $card->id,
            'items' => [['price_id' => $price->id]],
        ]);

    $invoice = $customer->invoices()->firstOrFail();

    // One `invoice.created` when it was issued, one `invoice.updated` when it
    // was paid — the outcome is `status` on the payload, not a name of its own.
    expect($invoice->status)->toBe(InvoiceStatus::Paid)
        ->and(Event::query()->where('type', OutboundEventType::InvoiceCreated)->count())->toBe(1)
        ->and(Event::query()->where('type', OutboundEventType::InvoiceUpdated)->count())->toBe(1)
        // Both invoice events reached the integrator. (The customer was
        // created before the endpoint existed, so its event has no delivery —
        // endpoints only receive what happens after they're registered.)
        ->and(WebhookDelivery::query()->where('status', WebhookDeliveryStatus::Succeeded)->count())
        ->toBe(2);

    Http::assertSent(function ($request) use ($generated, $invoice) {
        if (! str_contains($request->url(), 'integrator.test')) {
            return false;
        }

        $body = $request->body();
        $signatureHeader = $request->header('Bouclay-Signature')[0] ?? '';

        return app(SignOutboundWebhook::class)->verify($generated['secret'], $body, $signatureHeader)
            && str_contains($body, '"type":"invoice.updated"')
            // The outcome rides on the object, not the event name.
            && str_contains($body, '"status":"paid"')
            && str_contains($body, $invoice->public_id);
    });
});

test('marking an already paid invoice paid again does not emit a second invoice.updated event', function () {
    ['team' => $team, 'customer' => $customer, 'price' => $price] = invoiceFixture();

    WebhookEndpoint::factory()->for($team)->create([
        'url' => 'https://integrator.test/webhooks/bouclay',
    ]);

    $invoice = $customer->invoices()->create([
        'team_id' => $team->id,
        'customer_id' => $customer->id,
        'billed_to_customer_id' => $customer->id,
        'number' => 'INV-1001',
        'status' => InvoiceStatus::Open,
        'billing_reason' => InvoiceBillingReason::Manual,
        'collection_mode' => CollectionMode::Automatic,
        'currency' => 'NGN',
        'subtotal' => 500000,
        'discount_total' => 0,
        'tax_total' => 0,
        'total' => 500000,
        'amount_paid' => 0,
        'amount_due' => 500000,
        'finalized_at' => now(),
    ]);

    $payment = Payment::factory()->for($team)->for($invoice)->for($customer)->create([
        'status' => PaymentStatus::Succeeded,
        'amount' => $invoice->total,
        'currency' => 'NGN',
    ]);

    Http::fake(['integrator.test/*' => Http::response('ok', 200)]);

    $invoice->markPaid($payment);
    $invoice->markPaid($payment);

    // Collapsing to `invoice.updated` must not cost the idempotency that
    // `invoice.paid` had — a second markPaid is a no-op, not a second event.
    expect(Event::query()->where('type', OutboundEventType::InvoiceUpdated)->count())->toBe(1);
});
