<?php

namespace App\Enums;

/**
 * How Bouclay collects payment for a subscription (schema.md §4). Surfaced in
 * the dashboard as the two Stripe/Paddle choices (SUBSCRIPTIONS_DESIGN §3).
 */
enum CollectionMode: string
{
    case Automatic = 'automatic';
    case Manual = 'manual';

    /**
     * Get the human label for this collection mode.
     */
    public function label(): string
    {
        return match ($this) {
            self::Automatic => 'Automatically charge a saved card',
            self::Manual => 'Send an invoice to pay manually',
        };
    }
}
