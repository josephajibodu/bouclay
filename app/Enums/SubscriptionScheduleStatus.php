<?php

namespace App\Enums;

/**
 * A subscription schedule's lifecycle (schema.md §5). `Active` while it's
 * still stepping through phases; `Completed` once it reaches its terminal
 * step with `end_behavior=release` (the item collapses back to flat
 * billing); `Canceled` once it reaches its terminal step with
 * `end_behavior=cancel`. Never hard-deleted — kept for reporting history.
 */
enum SubscriptionScheduleStatus: string
{
    case Active = 'active';
    case Completed = 'completed';
    case Canceled = 'canceled';
}
