<?php

namespace App\States\Subscription;

use App\Enums\SubscriptionStatus;
use Illuminate\Support\Carbon;

/**
 * The subscription state-machine contract — one method per lifecycle action
 * (SUBSCRIPTIONS_DESIGN §4). Hand-rolled in the spirit of
 * spatie/laravel-model-states, with zero dependencies.
 *
 * Each method returns the state the subscription moves *to*. The default
 * implementation in {@see BaseSubscriptionState} throws for every action;
 * a concrete state overrides only the actions legal from it, so calling any
 * other action throws automatically — legality is expressed by omission.
 */
interface SubscriptionState
{
    /** First payment captured — begins the billing lifecycle. */
    public function activate(): SubscriptionState;

    /** A free trial ended — the item transitions to its regular price. */
    public function convert(): SubscriptionState;

    /** Suspend billing, optionally with a resume date. */
    public function pause(?Carbon $resumesAt = null): SubscriptionState;

    /** Resume a paused subscription. */
    public function resume(): SubscriptionState;

    /** End the subscription immediately. */
    public function cancel(): SubscriptionState;

    /** A renewal charge failed — enter dunning. */
    public function markPastDue(): SubscriptionState;

    /** A dunning retry succeeded — recover to active. */
    public function recover(): SubscriptionState;

    /** An incomplete subscription timed out before its first payment. */
    public function expire(): SubscriptionState;

    /** The enum value persisted to `subscriptions.status`. */
    public function status(): SubscriptionStatus;
}
