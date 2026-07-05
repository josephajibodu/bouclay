<?php

namespace App\Enums;

/**
 * A future change queued against a subscription to apply at the next boundary
 * — the Paddle "borrow" pattern (schema.md §4). "Cancel at period end" is a
 * scheduled change, not an immediate state transition (SUBSCRIPTIONS_DESIGN §4).
 */
enum ScheduledChangeAction: string
{
    case Cancel = 'cancel';
    case Pause = 'pause';
    case Resume = 'resume';
}
