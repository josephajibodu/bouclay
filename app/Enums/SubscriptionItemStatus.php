<?php

namespace App\Enums;

/**
 * A subscription item's lifecycle (schema.md §4). Removed items are kept for
 * history — never hard-deleted (SUBSCRIPTIONS_DESIGN §11.2).
 */
enum SubscriptionItemStatus: string
{
    case Active = 'active';
    case Removed = 'removed';
}
