<?php

namespace App\Services\Gateways;

/**
 * The inbound gateway events Bouclay acts on, normalized across drivers
 * (schema.md §9 — inbound, not the outbound events Bouclay emits to its own
 * integrators). A driver's `parseWebhookEvent()` maps its processor's wire
 * vocabulary onto these; anything it doesn't recognise returns null and is
 * acknowledged without effect.
 */
enum GatewayWebhookEventType: string
{
    case PaymentSucceeded = 'payment_succeeded';
    case PaymentFailed = 'payment_failed';
}
