<?php

namespace App\Enums;

/**
 * The payment processor a payment method was tokenised with. Nomba is the
 * only processor today; the enum leaves room for others (schema.md §2).
 */
enum PaymentProcessor: string
{
    case Nomba = 'nomba';

    /**
     * Get the human label for this processor.
     */
    public function label(): string
    {
        return match ($this) {
            self::Nomba => 'Nomba',
        };
    }
}
