<?php

namespace App\Enums;

/**
 * Outbound webhook delivery attempt status (schema.md § webhook_deliveries).
 */
enum WebhookDeliveryStatus: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    /**
     * The plain-language badge label shown in the dashboard.
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Succeeded => 'Delivered',
            self::Failed => 'Failed',
        };
    }

    /**
     * The semantic colour token the frontend maps to a dot/badge.
     */
    public function color(): string
    {
        return match ($this) {
            self::Succeeded => 'emerald',
            self::Pending => 'amber',
            self::Failed => 'red',
        };
    }
}
