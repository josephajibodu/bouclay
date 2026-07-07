<?php

namespace App\Services\Webhooks;

use App\Enums\WebhookDeliveryStatus;
use App\Models\WebhookDelivery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Execute one outbound webhook delivery attempt and update its status.
 */
class DeliverOutboundWebhookAttempt
{
    public function __construct(
        private readonly SignOutboundWebhook $signer,
    ) {
        //
    }

    public function handle(int $webhookDeliveryId): void
    {
        DB::transaction(function () use ($webhookDeliveryId): void {
            $delivery = WebhookDelivery::query()
                ->lockForUpdate()
                ->with(['event', 'webhookEndpoint'])
                ->find($webhookDeliveryId);

            if (! $delivery instanceof WebhookDelivery) {
                return;
            }

            if ($delivery->status !== WebhookDeliveryStatus::Pending) {
                return;
            }

            if ($delivery->next_attempt_at !== null && $delivery->next_attempt_at->isFuture()) {
                return;
            }

            $endpoint = $delivery->webhookEndpoint;

            if (! $endpoint->active) {
                $delivery->forceFill([
                    'status' => WebhookDeliveryStatus::Failed,
                    'next_attempt_at' => null,
                ])->save();

                return;
            }

            $payload = json_encode($delivery->event->toWebhookPayload(), JSON_THROW_ON_ERROR);
            $timestamp = time();
            $signature = $this->signer->header($endpoint->signing_secret, $payload, $timestamp);

            $succeeded = false;

            try {
                $response = Http::timeout(10)
                    ->withHeaders([
                        'Content-Type' => 'application/json',
                        'Bouclay-Signature' => $signature,
                        'User-Agent' => 'Bouclay-Webhooks/1.0',
                    ])
                    ->withBody($payload, 'application/json')
                    ->post($endpoint->url);

                $succeeded = $response->successful();
            } catch (\Throwable $exception) {
                Log::warning('Outbound webhook delivery failed', [
                    'webhook_delivery_id' => $delivery->id,
                    'webhook_endpoint_id' => $endpoint->id,
                    'message' => $exception->getMessage(),
                ]);
            }

            $attempts = $delivery->attempts + 1;

            if ($succeeded) {
                $delivery->forceFill([
                    'status' => WebhookDeliveryStatus::Succeeded,
                    'attempts' => $attempts,
                    'next_attempt_at' => null,
                ])->save();

                return;
            }

            if ($attempts >= WebhookDelivery::MAX_ATTEMPTS) {
                $delivery->forceFill([
                    'status' => WebhookDeliveryStatus::Failed,
                    'attempts' => $attempts,
                    'next_attempt_at' => null,
                ])->save();

                return;
            }

            $delivery->attempts = $attempts;

            $delivery->forceFill([
                'status' => WebhookDeliveryStatus::Pending,
                'attempts' => $attempts,
                'next_attempt_at' => $delivery->nextBackoffAt(),
            ])->save();
        });
    }
}
