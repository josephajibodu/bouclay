<?php

namespace App\Http\Controllers\Portal;

use App\Enums\ScheduledChangeAction;
use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Portal\Concerns\ResolvesPortalCustomer;
use App\Models\Subscription;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;

/**
 * Customer-initiated subscription changes from the self-service portal.
 */
class PortalSubscriptionController extends Controller
{
    use ResolvesPortalCustomer;

    /**
     * Schedule cancellation at the end of the current billing period.
     */
    public function cancel(string $token, string $publicId): RedirectResponse
    {
        $customer = $this->resolvePortalCustomer($token);

        $subscription = Subscription::query()
            ->where('public_id', $publicId)
            ->where('customer_id', $customer->id)
            ->firstOrFail();

        if (! $this->canCancelAtPeriodEnd($subscription)) {
            return redirect()
                ->route('portal.show', $customer->portal_token)
                ->with('toast', [
                    'type' => 'error',
                    'message' => 'This subscription can’t be canceled right now.',
                ]);
        }

        $hasPendingCancel = $subscription->scheduledChanges()
            ->where('action', ScheduledChangeAction::Cancel)
            ->whereNull('applied_at')
            ->exists();

        if ($hasPendingCancel) {
            return redirect()
                ->route('portal.show', $customer->portal_token)
                ->with('toast', [
                    'type' => 'error',
                    'message' => 'Cancellation is already scheduled for this subscription.',
                ]);
        }

        $effectiveAt = $subscription->current_period_end ?? Carbon::now();

        $subscription->scheduledChanges()->create([
            'action' => ScheduledChangeAction::Cancel,
            'effective_at' => $effectiveAt,
        ]);

        $subscription->forceFill([
            'canceled_at' => Carbon::now(),
            'ends_at' => $effectiveAt,
        ])->save();

        return redirect()
            ->route('portal.subscriptions.show', [
                'token' => $customer->portal_token,
                'publicId' => $publicId,
            ])
            ->with('toast', [
                'type' => 'success',
                'message' => 'Cancellation scheduled for '.$effectiveAt->format('j M').'. You’ll keep access until then.',
            ]);
    }

    /**
     * Whether a customer can self-cancel at period end from the portal.
     */
    private function canCancelAtPeriodEnd(Subscription $subscription): bool
    {
        return in_array($subscription->status, [
            SubscriptionStatus::Active,
            SubscriptionStatus::Trialing,
            SubscriptionStatus::PastDue,
            SubscriptionStatus::Paused,
        ], true);
    }
}
