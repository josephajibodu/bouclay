<?php

namespace App\Enums;

/**
 * Distinguishes a subscription's base charge from add-ons (schema.md §6).
 * The subscription's trial is anchored to the plan item; add-ons without
 * their own trial ride it (schema.md §5).
 */
enum SubscriptionItemKind: string
{
    case Plan = 'plan';
    case Addon = 'addon';
}
