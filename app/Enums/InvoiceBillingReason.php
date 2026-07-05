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
}
