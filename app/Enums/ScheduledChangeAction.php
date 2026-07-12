<?php

namespace App\Enums;

/**
 * A future change queued against a subscription to apply at the next boundary
 * — the Paddle "borrow" pattern (schema.md §6). "Cancel at period end" is a
 * scheduled change, not an immediate state transition (SUBSCRIPTIONS_DESIGN §4).
 *
 * `Update` is a deferred item change — downgrade, quantity change, price
 * swap, add-on removal — with the target state in `payload`
 * (`{subscription_item_id, price_id?, plan_id?, quantity?, remove?}`).
 */
enum ScheduledChangeAction: string
{
    case Cancel = 'cancel';
    case Pause = 'pause';
    case Resume = 'resume';
    case Update = 'update';
}
