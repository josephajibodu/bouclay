<?php

namespace App\Services\Gateways;

use App\Enums\ApiKeyMode;
use App\Enums\PaymentProcessor;
use App\Models\TeamProcessorConnection;
use Illuminate\Http\Request;

/**
 * The one boundary every payment processor sits behind (IMPLEMENTATION_V2
 * §V2-4). Adding a gateway is a single class implementing this contract plus a
 * registry entry in {@see GatewayManager} — connect form, checkout, charge,
 * refund, and webhooks all light up from there, with zero migrations. Every
 * call site resolves its driver through the manager (by
 * `payment_methods.processor` for a stored card, or the connection's
 * processor) and never references a concrete gateway class directly — enforced
 * by a grep test over `app/`.
 *
 * Failures are reported as {@see GatewayException} so call sites never learn a
 * driver's own exception type.
 */
interface PaymentGateway
{
    /**
     * The processor key this driver serves — its registry id.
     */
    public function processor(): PaymentProcessor;

    /**
     * What this gateway can do (currencies, refunds, tokenization). Validators
     * and UI read this to gate actions before attempting them.
     */
    public function capabilities(): GatewayCapabilities;

    /**
     * The credential manifest the connect form renders from and saves
     * validate against — the reason a new gateway needs no bespoke UI.
     */
    public function configSchema(): GatewayConfigSchema;

    /**
     * Assert a raw credential set (keyed by {@see configSchema()} field keys)
     * is accepted by the processor. Nothing is cached or persisted here.
     *
     * @param  array<string, string>  $credentials
     *
     * @throws GatewayException when the processor rejects or can't be reached
     */
    public function verifyCredentials(ApiKeyMode $mode, array $credentials): void;

    /**
     * Create a hosted checkout the customer is redirected to, optionally
     * tokenizing the card as a byproduct of the payment.
     *
     * @param  array<string, mixed>  $order  processor-agnostic order shape:
     *                                       {orderReference, customerId, customerEmail, amount, currency, callbackUrl, allowedPaymentMethods?}
     * @return array{checkoutLink: string, orderReference: string}
     *
     * @throws GatewayException
     */
    public function createCheckout(TeamProcessorConnection $connection, ApiKeyMode $mode, array $order, bool $tokenizeCard = true): array;

    /**
     * Charge a previously tokenized card directly (server-to-server, no
     * redirect) — the primitive behind renewals and stored-card charges. The
     * synchronous result is not settlement authority; confirm with
     * {@see verifyCharge()} before granting value.
     *
     * @param  array<string, mixed>  $order  same shape as {@see createCheckout()}
     * @return array{approved: bool, message: string}
     *
     * @throws GatewayException
     */
    public function chargeToken(TeamProcessorConnection $connection, ApiKeyMode $mode, array $order, string $tokenKey): array;

    /**
     * Confirm a charge actually settled, by its reference.
     *
     * @throws GatewayException
     */
    public function verifyCharge(TeamProcessorConnection $connection, ApiKeyMode $mode, string $reference): bool;

    /**
     * Reverse a (possibly partial) amount of a settled charge.
     *
     * @return array{success: bool, reference: string|null, message: string}
     *
     * @throws GatewayException
     */
    public function refund(TeamProcessorConnection $connection, ApiKeyMode $mode, string $chargeReference, int $amountMinor, string $currency): array;

    /**
     * Recover the card token minted by a completed checkout — the synchronous
     * fallback for when the webhook carrying it hasn't arrived yet. Returns
     * null when no token can be resolved (never throws for a missing token).
     *
     * @return array{tokenKey: string, brand?: string|null, last4?: string|null, expiry?: string|null, tokenExpiryMonth?: int|string|null, tokenExpiryYear?: int|string|null}|null
     */
    public function resolveToken(TeamProcessorConnection $connection, ApiKeyMode $mode, string $customerEmail, string $orderReference): ?array;

    /**
     * Revoke a card token on the processor, so removing a payment method in
     * Bouclay also removes it upstream.
     *
     * @throws GatewayException
     */
    public function revokeToken(TeamProcessorConnection $connection, ApiKeyMode $mode, string $tokenKey): void;

    /**
     * Whether an inbound request genuinely came from this processor for this
     * connection. Called before the payload is trusted or parsed.
     */
    public function verifyWebhookSignature(TeamProcessorConnection $connection, ApiKeyMode $mode, Request $request): bool;

    /**
     * Normalize a verified inbound payload into Bouclay's internal event
     * shape, or null for an event Bouclay doesn't act on (acknowledged, no
     * effect).
     *
     * @param  array<string, mixed>  $payload
     */
    public function parseWebhookEvent(array $payload): ?GatewayWebhookEvent;

    /**
     * Whether an inbound payload was produced by this connection's own
     * account — the reverse lookup for when a gateway can't be pointed at
     * `/webhooks/{processor}/{token}` and the URL carries no token to
     * identify the team by.
     *
     * Answering means comparing the payload's account identifiers against
     * stored credentials, which is exactly the pair of things only a driver
     * knows. Return false when the gateway offers nothing to match on.
     *
     * @param  array<string, mixed>  $payload
     */
    public function identifiesConnection(TeamProcessorConnection $connection, array $payload): bool;
}
