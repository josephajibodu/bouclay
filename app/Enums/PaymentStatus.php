<?php

namespace App\Enums;

/**
 * One charge attempt's outcome (schema.md §7). The dashboard calls this
 * object a "Transaction" (Paddle's word); the model/table stay `Payment`.
 */
enum PaymentStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Refunded = 'refunded';

    /**
     * The plain-language badge label shown in the dashboard.
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Processing => 'Processing',
            self::Succeeded => 'Completed',
            self::Failed => 'Failed',
            self::Refunded => 'Refunded',
        };
    }

    /**
     * The semantic colour token the frontend maps to a dot/badge.
     */
    public function color(): string
    {
        return match ($this) {
            self::Succeeded => 'emerald',
            self::Pending, self::Processing => 'amber',
            self::Failed => 'red',
            self::Refunded => 'zinc',
        };
    }
}
