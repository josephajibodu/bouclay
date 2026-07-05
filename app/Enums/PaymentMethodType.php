<?php

namespace App\Enums;

enum PaymentMethodType: string
{
    case Card = 'card';
    case Bank = 'bank';
    case Wallet = 'wallet';

    /**
     * Get the human label for this payment-method type.
     */
    public function label(): string
    {
        return match ($this) {
            self::Card => 'Card',
            self::Bank => 'Bank account',
            self::Wallet => 'Wallet',
        };
    }
}
