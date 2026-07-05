<?php

namespace App\Enums;

enum PaymentMethodStatus: string
{
    case Active = 'active';
    case Expired = 'expired';
    case Revoked = 'revoked';

    /**
     * Get the human label for this status.
     */
    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Expired => 'Expired',
            self::Revoked => 'Revoked',
        };
    }
}
