<?php

namespace App\States\Subscription;

use App\Enums\SubscriptionStatus;
use Illuminate\Support\Carbon;

/**
 * Billing on schedule. It can be paused, canceled, or fall to past_due when a
 * renewal charge fails.
 */
final class ActiveState extends BaseSubscriptionState
{
    public function status(): SubscriptionStatus
    {
        return SubscriptionStatus::Active;
    }

    public function pause(?Carbon $resumesAt = null): SubscriptionState
    {
        $this->subscription->paused_at = Carbon::now();
        $this->subscription->pause_resumes_at = $resumesAt;

        return $this->to(PausedState::class);
    }

    public function cancel(): SubscriptionState
    {
        $this->subscription->canceled_at = Carbon::now();
        $this->subscription->ends_at = Carbon::now();

        return $this->to(CanceledState::class);
    }

    public function markPastDue(): SubscriptionState
    {
        return $this->to(PastDueState::class);
    }
}
