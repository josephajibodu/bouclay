<?php

namespace App\Services\Gateways;

use App\Enums\ApiKeyMode;
use App\Enums\PaymentProcessor;
use App\Models\TeamProcessorConnection;
use App\Services\Nomba\NombaCheckout;

/**
 * The Nomba driver — a thin adapter over the existing {@see NombaCheckout}
 * wrapper (a refactor, not a rewrite: behaviour is identical, proven by the
 * existing Nomba suites staying green). Nomba settles NGN only and supports
 * partial refunds and tokenization.
 */
class NombaGateway implements PaymentGateway
{
    public function __construct(private readonly NombaCheckout $checkout) {}

    public function processor(): PaymentProcessor
    {
        return PaymentProcessor::Nomba;
    }

    public function capabilities(): GatewayCapabilities
    {
        return new GatewayCapabilities(
            currencies: ['NGN'],
            refunds: true,
            partialRefunds: true,
            tokenization: true,
        );
    }

    public function chargeToken(TeamProcessorConnection $connection, ApiKeyMode $mode, array $order, string $tokenKey): array
    {
        return $this->checkout->chargeTokenizedCard($connection, $mode, $order, $tokenKey);
    }

    public function verifyCharge(TeamProcessorConnection $connection, ApiKeyMode $mode, string $reference): bool
    {
        return $this->checkout->verifyOrderSucceeded($connection, $mode, $reference);
    }

    public function refund(TeamProcessorConnection $connection, ApiKeyMode $mode, string $chargeReference, int $amountMinor, string $currency): array
    {
        return $this->checkout->refund($connection, $mode, $chargeReference, $amountMinor, $currency);
    }
}
