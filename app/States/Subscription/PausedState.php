<?php

namespace App\States\Subscription;

use App\Enums\SubscriptionStatus;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Billing is suspended. It resumes to active (or back to trialing if the trial
 * clock hasn't run out) or can be canceled.
 */
final class PausedState extends BaseSubscriptionState
{
    public function status(): SubscriptionStatus
    {
        return SubscriptionStatus::Paused;
    }

    public function resume(): SubscriptionState
    {
        $this->subscription->paused_at = null;
        $this->subscription->pause_resumes_at = null;

        $trialEndsAt = $this->subscription->trial_ends_at;

        if ($trialEndsAt !== null && $trialEndsAt->isFuture()) {
            return $this->to(TrialingState::class);
        }

        return $this->to(ActiveState::class);
    }

    public function cancel(?CarbonInterface $endsAt = null): SubscriptionState
    {
        $this->subscription->canceled_at = Carbon::now();
        $this->subscription->ends_at = $endsAt !== null ? Carbon::instance($endsAt) : Carbon::now();

        return $this->to(CanceledState::class);
    }
}
