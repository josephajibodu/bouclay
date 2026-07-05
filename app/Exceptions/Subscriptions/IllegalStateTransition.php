<?php

namespace App\Exceptions\Subscriptions;

use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use RuntimeException;

/**
 * Thrown when an action is attempted on a subscription in a state that doesn't
 * permit it — e.g. pausing a canceled subscription. In the dashboard the UI
 * only offers legal actions, so this is defence-in-depth; the API surface
 * (Phase 10) catches it as a 409 (SUBSCRIPTIONS_DESIGN §4, §19).
 */
class IllegalStateTransition extends RuntimeException
{
    public static function make(Subscription $subscription, SubscriptionStatus $from, string $action): self
    {
        return new self(sprintf(
            'Cannot %s a subscription that is %s (%s).',
            $action,
            $from->label(),
            $subscription->public_id ?? 'unsaved',
        ));
    }
}
