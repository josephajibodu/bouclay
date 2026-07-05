<?php

namespace App\States\Subscription;

use App\Enums\SubscriptionStatus;

/**
 * Terminal. The subscription has ended; no action transitions out of here
 * (every contract method inherits the throwing default).
 */
final class CanceledState extends BaseSubscriptionState
{
    public function status(): SubscriptionStatus
    {
        return SubscriptionStatus::Canceled;
    }
}
