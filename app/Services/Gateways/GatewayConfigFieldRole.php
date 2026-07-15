<?php

namespace App\Services\Gateways;

/**
 * What a manifest field is *for*, so Bouclay can act on it without knowing
 * the gateway's key names (IMPLEMENTATION_V2 §V2-4).
 *
 * This is deliberately about purpose, not shape: the webhooks page needs to
 * ask "which of your fields, if any, signs inbound events?" — not "give me
 * your `webhook_secret`". A gateway that signs with credentials it already
 * holds (Paystack HMACs the raw body with the secret key) simply declares no
 * field with the {@see self::WebhookSecret} role, and the page has nothing to
 * ask for.
 */
enum GatewayConfigFieldRole: string
{
    /** Needed to authenticate API calls. */
    case Credential = 'credential';

    /** Verifies the signature on inbound webhooks from this gateway. */
    case WebhookSecret = 'webhook_secret';
}
