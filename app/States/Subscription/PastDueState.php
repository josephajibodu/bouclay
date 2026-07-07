<?php

namespace App\States\Subscription;

use App\Enums\SubscriptionStatus;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * A renewal payment failed and Bouclay is retrying (dunning). It recovers to
 * active when a retry succeeds, or is canceled when retries are exhausted.
 */
final class PastDueState extends BaseSubscriptionState
{
    public function status(): SubscriptionStatus
    {
        return SubscriptionStatus::PastDue;
    }

    public function recover(): SubscriptionState
    {
        return $this->to(ActiveState::class);
    }

    public function pause(?Carbon $resumesAt = null): SubscriptionState
    {
        $this->subscription->paused_at = Carbon::now();
        $this->subscription->pause_resumes_at = $resumesAt;

        return $this->to(PausedState::class);
    }

    public function cancel(?CarbonInterface $endsAt = null): SubscriptionState
    {
        $this->subscription->canceled_at = Carbon::now();
        $this->subscription->ends_at = $endsAt !== null ? Carbon::instance($endsAt) : Carbon::now();

        return $this->to(CanceledState::class);
    }
}
