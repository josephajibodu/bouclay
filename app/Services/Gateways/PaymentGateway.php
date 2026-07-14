<?php

namespace App\Services\Gateways;

use App\Enums\ApiKeyMode;
use App\Enums\PaymentProcessor;
use App\Models\TeamProcessorConnection;

/**
 * The one boundary every payment processor sits behind (IMPLEMENTATION_V2
 * §V2-4). Adding a gateway is a single class implementing this contract plus a
 * registry entry in {@see GatewayManager} — checkout, charge, and refund all
 * light up from there, with zero migrations. Every money call site resolves
 * its driver through the manager (by `payment_methods.processor` for a stored
 * card, or the connection's processor) and never references a concrete gateway
 * class directly.
 *
 * This phase (V2-4) wires the money paths — charge, verify, refund. Hosted
 * checkout, credential verification, and webhook parsing move behind the same
 * interface as their call sites are swept.
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
     * Charge a previously tokenized card directly (server-to-server, no
     * redirect) — the primitive behind renewals and stored-card charges. The
     * synchronous result is not settlement authority; confirm with
     * {@see verifyCharge()} before granting value.
     *
     * @param  array<string, mixed>  $order  processor-agnostic order shape:
     *                                       {orderReference, customerId, customerEmail, amount, currency, callbackUrl}
     * @return array{approved: bool, message: string}
     */
    public function chargeToken(TeamProcessorConnection $connection, ApiKeyMode $mode, array $order, string $tokenKey): array;

    /**
     * Confirm a charge actually settled, by its reference.
     */
    public function verifyCharge(TeamProcessorConnection $connection, ApiKeyMode $mode, string $reference): bool;

    /**
     * Reverse a (possibly partial) amount of a settled charge.
     *
     * @return array{success: bool, reference: string|null, message: string}
     */
    public function refund(TeamProcessorConnection $connection, ApiKeyMode $mode, string $chargeReference, int $amountMinor, string $currency): array;
}
