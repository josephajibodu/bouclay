<?php

namespace App\Services\Gateways;

/**
 * One inbound gateway event, normalized (IMPLEMENTATION_V2 §V2-4). A driver's
 * `parseWebhookEvent()` translates its processor's payload into this shape and
 * one shared settlement action applies it — so settlement logic is written
 * once, and a new gateway only teaches Bouclay its wire format.
 */
readonly class GatewayWebhookEvent
{
    /**
     * @param  string  $orderReference  the reference the charge was created under
     * @param  array{tokenKey: string, brand?: string|null, last4?: string|null, tokenExpiryMonth?: int|string|null, tokenExpiryYear?: int|string|null}|null  $token
     *                                                                                                                                                                a card token minted as a byproduct of this payment, if any
     * @param  array<string, mixed>  $raw  the untouched payload, stored on the payment for audit
     */
    public function __construct(
        public GatewayWebhookEventType $type,
        public string $orderReference,
        public ?array $token = null,
        public ?string $failureReason = null,
        public array $raw = [],
    ) {}

    public function isSuccess(): bool
    {
        return $this->type === GatewayWebhookEventType::PaymentSucceeded;
    }
}
