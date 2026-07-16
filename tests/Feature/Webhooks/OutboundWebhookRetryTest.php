<?php

use App\Enums\OutboundEventType;
use App\Enums\WebhookDeliveryStatus;
use App\Models\Event;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\Webhooks\DeliverOutboundWebhookAttempt;
use Illuminate\Support\Facades\Http;

test('a failed delivery stays pending with a future retry time and succeeds on retry', function () {
    ['team' => $team] = invoiceFixture();

    $integratorUrl = 'https://integrator.test/webhooks/bouclay';
    $generated = WebhookEndpoint::generateSigningSecret();

    $endpoint = WebhookEndpoint::factory()->for($team)->create([
        'url' => $integratorUrl,
        'signing_secret' => $generated['secret'],
    ]);

    $event = Event::factory()->for($team)->create([
        'type' => OutboundEventType::InvoiceUpdated,
    ]);

    $delivery = WebhookDelivery::factory()->create([
        'webhook_endpoint_id' => $endpoint->id,
        'event_id' => $event->id,
        'status' => WebhookDeliveryStatus::Pending,
        'attempts' => 0,
        'next_attempt_at' => now(),
    ]);

    Http::fake([
        'integrator.test/*' => Http::sequence()
            ->push('error', 500)
            ->push('ok', 200),
    ]);

    app(DeliverOutboundWebhookAttempt::class)->handle($delivery->id);

    $delivery->refresh();

    expect($delivery->status)->toBe(WebhookDeliveryStatus::Pending)
        ->and($delivery->attempts)->toBe(1)
        ->and($delivery->next_attempt_at)->not->toBeNull()
        ->and($delivery->next_attempt_at->isFuture())->toBeTrue();

    $delivery->forceFill(['next_attempt_at' => now()->subSecond()])->save();

    app(DeliverOutboundWebhookAttempt::class)->handle($delivery->id);

    expect($delivery->refresh()->status)->toBe(WebhookDeliveryStatus::Succeeded)
        ->and($delivery->attempts)->toBe(2);
});

test('the pending delivery sweep dispatches retries that are due', function () {
    ['team' => $team] = invoiceFixture();

    $endpoint = WebhookEndpoint::factory()->for($team)->create([
        'url' => 'https://integrator.test/webhooks/bouclay',
    ]);

    $event = Event::factory()->for($team)->create();

    $delivery = WebhookDelivery::factory()->create([
        'webhook_endpoint_id' => $endpoint->id,
        'event_id' => $event->id,
        'status' => WebhookDeliveryStatus::Pending,
        'attempts' => 1,
        'next_attempt_at' => now()->subMinute(),
    ]);

    Http::fake(['integrator.test/*' => Http::response('ok', 200)]);

    $this->artisan('webhooks:deliver-pending')->assertSuccessful();

    expect($delivery->refresh()->status)->toBe(WebhookDeliveryStatus::Succeeded);
});
