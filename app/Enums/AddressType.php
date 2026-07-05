<?php

namespace App\Enums;

enum AddressType: string
{
    case Billing = 'billing';
    case Shipping = 'shipping';

    /**
     * Get the human label for this address type.
     */
    public function label(): string
    {
        return match ($this) {
            self::Billing => 'Billing',
            self::Shipping => 'Shipping',
        };
    }
}
