<?php

namespace App\Actions\Subscriptions;

use App\Models\Discount;
use App\Models\DiscountRedemption;
use App\Models\Subscription;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Redeem a discount onto a subscription (schema.md §7) — the single seam used
 * both at signup ({@see CreateSubscription}) and when a merchant attaches a
 * discount to a live subscription.
 *
 * A subscription holds one `discount_id` (no stacking): redeeming replaces any
 * existing discount, and the new redemption carries its own fresh
 * `remaining_intervals` budget (GAP-1). Eligibility is gated here — the
 * subscription must qualify and the discount must be under its global cap.
 */
class RedeemDiscount
{
    public function handle(Subscription $subscription, Discount $discount, ?Carbon $now = null): DiscountRedemption
    {
        $now ??= Carbon::now();

        if (! $discount->isRedeemableBySubscriptionItems($subscription->items()->get(), $subscription->currency)) {
            throw new InvalidArgumentException(
                "Discount {$discount->public_id} isn't eligible for this subscription (or has reached its redemption limit)."
            );
        }

        $subscription->forceFill(['discount_id' => $discount->id])->save();

        $redemption = $subscription->discountRedemptions()->create([
            'discount_id' => $discount->id,
            'customer_id' => $subscription->customer_id,
            'remaining_intervals' => $discount->initialRemainingIntervals(),
            'applied_at' => $now,
        ]);

        $discount->increment('times_redeemed');

        return $redemption;
    }

    /**
     * Detach the current discount from a subscription — future cycles stop
     * applying it; already-issued invoices keep their line-level discounts.
     */
    public function remove(Subscription $subscription): void
    {
        $subscription->forceFill(['discount_id' => null])->save();
    }
}
