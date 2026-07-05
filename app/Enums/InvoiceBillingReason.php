<?php

namespace App\Enums;

/**
 * Why an invoice was created (schema.md §7).
 */
enum InvoiceBillingReason: string
{
    case SubscriptionCreate = 'subscription_create';
    case SubscriptionCycle = 'subscription_cycle';
    case SubscriptionUpdate = 'subscription_update';
    case Manual = 'manual';

    /**
     * Plain-language label for the invoice detail page.
     */
    public function label(): string
    {
        return match ($this) {
            self::SubscriptionCreate => 'New subscription',
            self::SubscriptionCycle => 'Subscription renewal',
            self::SubscriptionUpdate => 'Subscription update',
            self::Manual => 'One-off invoice',
        };
    }
}
