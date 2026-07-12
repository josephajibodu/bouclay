<?php

namespace App\Enums;

/**
 * The payment gateway a payment method was tokenised with — a driver-registry
 * key, not a closed set; it grows as drivers ship (schema.md Enums appendix).
 * Tokens are gateway-bound: a stored card always charges through the
 * processor that minted it.
 */
enum PaymentProcessor: string
{
    case Nomba = 'nomba';
    case Paystack = 'paystack';
    case Flutterwave = 'flutterwave';

    /**
     * Get the human label for this processor.
     */
    public function label(): string
    {
        return match ($this) {
            self::Nomba => 'Nomba',
            self::Paystack => 'Paystack',
            self::Flutterwave => 'Flutterwave',
        };
    }
}
