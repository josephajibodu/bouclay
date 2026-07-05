<?php

namespace App\States\Subscription;

use App\Enums\SubscriptionStatus;

/**
 * Terminal. The first payment was never completed, so the subscription never
 * started; no action transitions out of here.
 */
final class IncompleteExpiredState extends BaseSubscriptionState
{
    public function status(): SubscriptionStatus
    {
        return SubscriptionStatus::IncompleteExpired;
    }
}
