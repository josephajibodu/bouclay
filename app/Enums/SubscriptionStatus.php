<?php

namespace App\Enums;

use App\Models\Subscription;
use App\States\Subscription\ActiveState;
use App\States\Subscription\CanceledState;
use App\States\Subscription\IncompleteExpiredState;
use App\States\Subscription\IncompleteState;
use App\States\Subscription\PastDueState;
use App\States\Subscription\PausedState;
use App\States\Subscription\SubscriptionState;
use App\States\Subscription\TrialingState;

/**
 * The seven subscription lifecycle states (schema.md §4).
 *
 * This enum is the single source of truth for two things that must never
 * drift apart (SUBSCRIPTIONS_DESIGN §4, §9): it resolves the hand-rolled
 * state-machine class for a subscription (`stateFor`), and it carries the
 * plain-language UI metadata the badge/banner read (`label`/`color`/
 * `description`). Users never see the raw enum value.
 */
enum SubscriptionStatus: string
{
    case Incomplete = 'incomplete';
    case IncompleteExpired = 'incomplete_expired';
    case Trialing = 'trialing';
    case Active = 'active';
    case PastDue = 'past_due';
    case Paused = 'paused';
    case Canceled = 'canceled';

    /**
     * Resolve the state-machine class that owns the legal transitions from
     * this status. The state object holds no data beyond the subscription —
     * legality lives in which methods each class implements.
     */
    public function stateFor(Subscription $subscription): SubscriptionState
    {
        return match ($this) {
            self::Incomplete => new IncompleteState($subscription),
            self::IncompleteExpired => new IncompleteExpiredState($subscription),
            self::Trialing => new TrialingState($subscription),
            self::Active => new ActiveState($subscription),
            self::PastDue => new PastDueState($subscription),
            self::Paused => new PausedState($subscription),
            self::Canceled => new CanceledState($subscription),
        };
    }

    /**
     * The plain-language badge label shown in the dashboard.
     */
    public function label(): string
    {
        return match ($this) {
            self::Incomplete => 'Awaiting payment',
            self::IncompleteExpired => 'Expired',
            self::Trialing => 'On trial',
            self::Active => 'Active',
            self::PastDue => 'Past due',
            self::Paused => 'Paused',
            self::Canceled => 'Canceled',
        };
    }

    /**
     * The semantic colour token the frontend maps to a dot/badge (SUBSCRIPTIONS_DESIGN §9.4).
     * Semantic, not decorative: amber = pending, emerald = healthy, red = failing,
     * blue = trial, violet = suspended, zinc = ended/inert.
     */
    public function color(): string
    {
        return match ($this) {
            self::Incomplete => 'amber',
            self::Trialing => 'blue',
            self::Active => 'emerald',
            self::PastDue => 'red',
            self::Paused => 'violet',
            self::Canceled, self::IncompleteExpired => 'zinc',
        };
    }

    /**
     * A one-line, plain-language explanation of the current situation.
     */
    public function description(): string
    {
        return match ($this) {
            self::Incomplete => "The first payment hasn't gone through yet. Access shouldn't be granted until it does.",
            self::IncompleteExpired => 'The first payment was never completed, so this subscription never started.',
            self::Trialing => 'Currently on a free trial. No payment has been taken yet.',
            self::Active => 'Billing on schedule. The customer has access.',
            self::PastDue => 'A renewal payment failed. Bouclay is retrying.',
            self::Paused => 'Billing is paused. No charges will be made while paused.',
            self::Canceled => 'This subscription has ended. No further charges.',
        };
    }

    /**
     * Terminal states can never transition again.
     */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Canceled, self::IncompleteExpired], true);
    }

    /**
     * The "All active" filter union — the states a merchant cares about
     * day-to-day (SUBSCRIPTIONS_DESIGN §6.3).
     *
     * @return list<self>
     */
    public static function activeSet(): array
    {
        return [self::Trialing, self::Active, self::PastDue];
    }
}
