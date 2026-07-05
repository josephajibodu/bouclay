<?php

namespace App\States\Subscription;

use App\Enums\SubscriptionStatus;
use Illuminate\Support\Carbon;

/**
 * On a free trial — no payment taken yet. It converts to the regular price
 * when the trial ends, and can be paused or canceled meanwhile.
 */
final class TrialingState extends BaseSubscriptionState
{
    public function status(): SubscriptionStatus
    {
        return SubscriptionStatus::Trialing;
    }

    public function convert(): SubscriptionState
    {
        return $this->to(ActiveState::class);
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
}
