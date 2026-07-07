<?php

namespace App\States\Subscription;

use App\Exceptions\Subscriptions\IllegalStateTransition;
use App\Models\Subscription;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Every action is illegal until a concrete state says otherwise. Subclasses
 * override only the transitions valid from their state; everything they leave
 * alone inherits the throwing default here (SUBSCRIPTIONS_DESIGN §4).
 */
abstract class BaseSubscriptionState implements SubscriptionState
{
    public function __construct(protected readonly Subscription $subscription) {}

    public function activate(): SubscriptionState
    {
        return $this->illegal('activate');
    }

    public function convert(): SubscriptionState
    {
        return $this->illegal('convert');
    }

    public function pause(?Carbon $resumesAt = null): SubscriptionState
    {
        return $this->illegal('pause');
    }

    public function resume(): SubscriptionState
    {
        return $this->illegal('resume');
    }

    public function cancel(?CarbonInterface $endsAt = null): SubscriptionState
    {
        return $this->illegal('cancel');
    }

    public function markPastDue(): SubscriptionState
    {
        return $this->illegal('markPastDue');
    }

    public function recover(): SubscriptionState
    {
        return $this->illegal('recover');
    }

    public function expire(): SubscriptionState
    {
        return $this->illegal('expire');
    }

    /**
     * Reject an action not permitted from this state.
     */
    protected function illegal(string $action): never
    {
        throw IllegalStateTransition::make($this->subscription, $this->status(), $action);
    }

    /**
     * Build the target state for this subscription.
     *
     * @param  class-string<SubscriptionState>  $state
     */
    protected function to(string $state): SubscriptionState
    {
        return new $state($this->subscription);
    }
}
