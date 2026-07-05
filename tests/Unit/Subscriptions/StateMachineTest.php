<?php

use App\Exceptions\Subscriptions\IllegalStateTransition;
use App\Models\Subscription;
use App\States\Subscription\ActiveState;
use App\States\Subscription\BaseSubscriptionState;
use App\States\Subscription\CanceledState;
use App\States\Subscription\IncompleteExpiredState;
use App\States\Subscription\IncompleteState;
use App\States\Subscription\PastDueState;
use App\States\Subscription\PausedState;
use App\States\Subscription\TrialingState;
use Tests\TestCase;

// The state machine is pure logic, but instantiating Eloquent models needs the
// framework booted — bind the app TestCase (no database writes happen here).
uses(TestCase::class);

/**
 * Every lifecycle action in the contract.
 */
const ACTIONS = ['activate', 'convert', 'pause', 'resume', 'cancel', 'markPastDue', 'recover', 'expire'];

/**
 * The legality matrix (SUBSCRIPTIONS_DESIGN §4): state class => [action => expected next state class].
 * Any (state, action) pair NOT listed here must throw IllegalStateTransition.
 *
 * @return array<class-string<BaseSubscriptionState>, array<string, class-string<BaseSubscriptionState>>>
 */
function legalTransitions(): array
{
    return [
        IncompleteState::class => [
            'activate' => ActiveState::class,
            'expire' => IncompleteExpiredState::class,
            'cancel' => CanceledState::class,
        ],
        TrialingState::class => [
            'convert' => ActiveState::class,
            'pause' => PausedState::class,
            'cancel' => CanceledState::class,
        ],
        ActiveState::class => [
            'pause' => PausedState::class,
            'cancel' => CanceledState::class,
            'markPastDue' => PastDueState::class,
        ],
        PastDueState::class => [
            'recover' => ActiveState::class,
            'cancel' => CanceledState::class,
        ],
        PausedState::class => [
            'resume' => ActiveState::class,
            'cancel' => CanceledState::class,
        ],
        CanceledState::class => [],
        IncompleteExpiredState::class => [],
    ];
}

test('every legal action moves to the expected state', function () {
    foreach (legalTransitions() as $stateClass => $allowed) {
        foreach ($allowed as $action => $expected) {
            $state = new $stateClass(new Subscription);

            expect($state->{$action}())->toBeInstanceOf($expected);
        }
    }
});

test('every illegal action throws IllegalStateTransition', function () {
    foreach (legalTransitions() as $stateClass => $allowed) {
        foreach (ACTIONS as $action) {
            if (array_key_exists($action, $allowed)) {
                continue;
            }

            $state = new $stateClass(new Subscription);

            expect(fn () => $state->{$action}())
                ->toThrow(IllegalStateTransition::class);
        }
    }
});

test('terminal states permit no transitions at all', function () {
    foreach ([CanceledState::class, IncompleteExpiredState::class] as $stateClass) {
        foreach (ACTIONS as $action) {
            $state = new $stateClass(new Subscription);

            expect(fn () => $state->{$action}())
                ->toThrow(IllegalStateTransition::class);
        }
    }
});
