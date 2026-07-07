<?php

namespace App\States\Subscription;

use App\Enums\SubscriptionStatus;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Created but the first payment hasn't landed. It can activate on payment,
 * expire if it's never paid, or be canceled outright.
 */
final class IncompleteState extends BaseSubscriptionState
{
    public function status(): SubscriptionStatus
    {
        return SubscriptionStatus::Incomplete;
    }

    public function activate(): SubscriptionState
    {
        return $this->to(ActiveState::class);
    }

    public function expire(): SubscriptionState
    {
        return $this->to(IncompleteExpiredState::class);
    }

    public function cancel(?CarbonInterface $endsAt = null): SubscriptionState
    {
        $this->subscription->canceled_at = Carbon::now();
        $this->subscription->ends_at = $endsAt !== null ? Carbon::instance($endsAt) : Carbon::now();

        return $this->to(CanceledState::class);
    }
}
