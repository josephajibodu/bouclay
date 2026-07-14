<?php

namespace App\Services\Gateways;

/**
 * What a payment gateway can do (IMPLEMENTATION_V2 §V2-4). Validators read
 * these — a USD price can't create a checkout on a gateway that can't settle
 * USD, and a refund button is disabled on a gateway that can't refund — so a
 * capability gap surfaces as copy up front, never a mid-flight failure.
 *
 * @phpstan-type Currency string
 */
readonly class GatewayCapabilities
{
    /**
     * @param  list<string>  $currencies  ISO-4217 codes the gateway settles
     */
    public function __construct(
        public array $currencies,
        public bool $refunds,
        public bool $partialRefunds,
        public bool $tokenization,
    ) {}

    public function supportsCurrency(string $currency): bool
    {
        return in_array(strtoupper($currency), array_map('strtoupper', $this->currencies), true);
    }
}
