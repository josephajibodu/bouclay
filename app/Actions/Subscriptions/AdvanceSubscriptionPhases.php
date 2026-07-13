<?php

namespace App\Actions\Subscriptions;

use App\Actions\Invoicing\CollectInvoice;
use App\Actions\Invoicing\CreateInvoice;
use App\Enums\BillingInterval;
use App\Enums\CollectionMode;
use App\Enums\InvoiceBillingReason;
use App\Enums\InvoiceLineKind;
use App\Enums\SubscriptionItemStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\TrialEndBehavior;
use App\Models\Invoice;
use App\Models\PaymentMethod;
use App\Models\Price;
use App\Models\PricePhase;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\SubscriptionItem;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Advance a subscription across a price-phase boundary (schema.md §5).
 *
 * This is the generalized trial-conversion worker: a *simple* trial is the
 * degenerate single-boundary case where the item keeps one price, the trial
 * clock runs out, and the subscription converts `trialing → active`. A phased
 * price is the general case — at each boundary the item's `current_phase_sequence`
 * advances and its effective charge price swaps to the next phase
 * ({@see SubscriptionItem::effectiveChargePrice()}), until the schedule lands
 * on its steady-state (final) phase, after which normal renewal takes over.
 *
 * State threading (schema.md §5): while the next phase is still free the
 * subscription stays `trialing`; the moment it lands on a paid phase it
 * converts and bills. When a free trial ends with no payment method, the
 * subscription honours `trial_end_behavior` (cancel / pause / create_invoice).
 */
class AdvanceSubscriptionPhases
{
    public function __construct(
        private readonly CreateInvoice $createInvoice,
        private readonly CollectInvoice $collectInvoice,
    ) {
        //
    }

    public function handle(Subscription $subscription): ?Invoice
    {
        if (! $this->isDue($subscription)) {
            return null;
        }

        $subscription->loadMissing(['customer', 'items.price.product', 'items.price.phases.chargePrice', 'paymentMethod', 'team']);

        /** @var Invoice|null $invoice */
        $invoice = DB::transaction(function () use ($subscription): ?Invoice {
            $now = Carbon::now();

            $this->advanceDueItems($subscription, $now);

            // A subsequent phase can itself be a free trial — if any item is
            // still trialing after advancing, the subscription stays `trialing`
            // and simply re-anchors its clock to the next boundary.
            if ($this->stillTrialing($subscription)) {
                $this->reanchorTrialClock($subscription);

                return null;
            }

            return $this->settleAfterBoundary($subscription, $now);
        });

        if ($invoice === null) {
            return null;
        }

        $this->collectInvoice->handle(
            $subscription->team,
            $invoice,
            $this->resolvePaymentMethod($subscription),
        );

        return $invoice;
    }

    /**
     * A subscription is due for phase advancement when a free trial's clock
     * has run out, or when an active subscription still threading a paid ramp
     * has reached its current phase's boundary (its `current_period_end`).
     */
    private function isDue(Subscription $subscription): bool
    {
        $now = Carbon::now();

        if ($subscription->status === SubscriptionStatus::Trialing) {
            return $subscription->trial_ends_at !== null && $subscription->trial_ends_at->lte($now);
        }

        if ($subscription->status === SubscriptionStatus::Active) {
            return $subscription->current_period_end !== null
                && $subscription->current_period_end->lte($now)
                && $this->hasItemProgressingThroughPhases($subscription);
        }

        return false;
    }

    private function hasItemProgressingThroughPhases(Subscription $subscription): bool
    {
        return $subscription->items->contains(
            fn (SubscriptionItem $item): bool => $item->status === SubscriptionItemStatus::Active
                && $item->isProgressingThroughPhases(),
        );
    }

    /**
     * Advance every item sitting on a boundary: a phased item steps to its next
     * phase (re-arming its trial clock if that phase is itself free); a simple
     * trial item simply clears its trial clock.
     */
    private function advanceDueItems(Subscription $subscription, Carbon $now): void
    {
        foreach ($subscription->items as $item) {
            if ($item->status !== SubscriptionItemStatus::Active) {
                continue;
            }

            if ($item->isProgressingThroughPhases()) {
                $this->stepItemToNextPhase($item, $now);

                continue;
            }

            // Simple trial (or a final-phase item) whose clock has elapsed.
            if ($item->trial_ends_at !== null && $item->trial_ends_at->lte($now)) {
                $item->forceFill(['trial_ends_at' => null])->save();
            }
        }
    }

    private function stepItemToNextPhase(SubscriptionItem $item, Carbon $now): void
    {
        $nextSequence = (int) $item->current_phase_sequence + 1;
        $nextPhase = $item->price->phases
            ->first(fn (PricePhase $phase): bool => $phase->sequence === $nextSequence);

        // The new phase is a free trial only while its charge price is ₦0 —
        // then the item keeps a trial clock; otherwise the trial ends here.
        $nextIsFree = $nextPhase !== null && ($nextPhase->chargePrice->unit_amount ?? 0) === 0;

        $item->forceFill([
            'current_phase_sequence' => $nextSequence,
            'trial_ends_at' => $nextIsFree && $nextPhase !== null
                ? $this->addInterval($now->copy(), $nextPhase->duration_interval, $nextPhase->duration_count)
                : null,
        ])->save();
    }

    private function stillTrialing(Subscription $subscription): bool
    {
        return $subscription->status === SubscriptionStatus::Trialing
            && $subscription->items->contains(
                fn (SubscriptionItem $item): bool => $item->status === SubscriptionItemStatus::Active
                    && $item->trial_ends_at !== null
                    && $item->trial_ends_at->isFuture(),
            );
    }

    /**
     * Keep a still-trialing subscription in `trialing` but re-point its
     * denormalised clock (and renewal clock) at the next boundary.
     */
    private function reanchorTrialClock(Subscription $subscription): void
    {
        $next = $this->earliestActiveTrialEnd($subscription);

        $subscription->forceFill([
            'trial_ends_at' => $next,
            'current_period_end' => $next ?? $subscription->current_period_end,
        ])->save();
    }

    /**
     * Convert the subscription and bill the now-effective prices — or, when a
     * free trial ends with no way to charge, honour `trial_end_behavior`.
     */
    private function settleAfterBoundary(Subscription $subscription, Carbon $now): ?Invoice
    {
        $subscription->trial_ends_at = null;

        // `trial_end_behavior` only governs a *free trial* that runs out with
        // no way to charge. An already-active paid ramp just bills its next
        // phase and lets normal dunning handle a decline.
        if ($subscription->status === SubscriptionStatus::Trialing && ! $this->canBill($subscription)) {
            // No behavior set falls back to `create_invoice` — issue the open
            // invoice rather than silently cancelling access (Stripe default).
            match ($subscription->trial_end_behavior) {
                TrialEndBehavior::Cancel => $subscription->apply('cancel'),
                TrialEndBehavior::Pause => $subscription->apply('pause'),
                TrialEndBehavior::CreateInvoice, null => $this->startBilling($subscription, $now),
            };

            return null;
        }

        return $this->startBilling($subscription, $now);
    }

    /**
     * Whether the subscription can actually be charged now — either a card is
     * on file, or the merchant asked to issue an open invoice regardless
     * (Stripe `missing_payment_method` = `create_invoice`).
     */
    private function canBill(Subscription $subscription): bool
    {
        if ($subscription->payment_method_id !== null) {
            return true;
        }

        return $subscription->trial_end_behavior === TrialEndBehavior::CreateInvoice;
    }

    private function startBilling(Subscription $subscription, Carbon $now): ?Invoice
    {
        // A trialing subscription converts; an active ramp stays active.
        if ($subscription->status === SubscriptionStatus::Trialing) {
            $subscription->apply('convert');
        }

        $this->resetBillingPeriod($subscription, $now);

        $lines = $this->buildBoundaryLines($subscription);

        if ($lines === []) {
            return null;
        }

        $dueAt = $subscription->collection_mode === CollectionMode::Manual
            ? $now->copy()->addDays(7)
            : null;

        // The conversion invoice is the discount's first application on a
        // free-trial subscription (schema.md §7, GAP-1).
        $redemption = $subscription->activeDiscountRedemption();

        $invoice = $this->createInvoice->handle(
            team: $subscription->team,
            customer: $subscription->customer,
            billingReason: InvoiceBillingReason::SubscriptionCreate,
            collectionMode: $subscription->collection_mode,
            lines: $lines,
            subscription: $subscription,
            dueAt: $dueAt,
            discount: $redemption?->discount,
        );

        if ($redemption !== null && $invoice->discount_total > 0) {
            $redemption->recordApplied();
        }

        return $invoice;
    }

    private function resetBillingPeriod(Subscription $subscription, Carbon $now): void
    {
        $anchorItem = $subscription->items
            ->first(fn (SubscriptionItem $item): bool => $item->status === SubscriptionItemStatus::Active);
        $anchorPrice = $anchorItem?->effectiveChargePrice();

        if ($anchorPrice === null) {
            return;
        }

        $periodStart = ($subscription->billing_cycle_anchor_on_trial_end ?? 'now') === 'unchanged'
            && $subscription->current_period_end !== null
            ? $subscription->current_period_end->copy()
            : $now->copy();

        $subscription->forceFill([
            'current_period_start' => $periodStart,
            'current_period_end' => $this->addInterval(
                $periodStart->copy(),
                $anchorPrice->billing_interval ?? BillingInterval::Month,
                $anchorPrice->billing_frequency,
            ),
        ])->save();
    }

    /**
     * The boundary invoice bundles every active item at its now-effective
     * price — plan and add-ons alike (GAP-4: add-ons ride the plan item's
     * trial and are first invoiced here, together).
     *
     * @return list<array{subscriptionItem: SubscriptionItem, price: Price, product: Product, kind: InvoiceLineKind, description: string, unitAmount: int, quantity: int}>
     */
    private function buildBoundaryLines(Subscription $subscription): array
    {
        return $subscription->items
            ->filter(fn (SubscriptionItem $item): bool => $item->status === SubscriptionItemStatus::Active)
            ->map(function (SubscriptionItem $item): array {
                $price = $item->effectiveChargePrice();
                $product = $price->product;

                return [
                    'subscriptionItem' => $item,
                    'price' => $price,
                    'product' => $product,
                    'kind' => InvoiceLineKind::from($item->kind->value),
                    'description' => $product->name.' · '.$price->toPickerLabel(),
                    'unitAmount' => $price->unit_amount ?? 0,
                    'quantity' => $item->quantity,
                ];
            })
            ->values()
            ->all();
    }

    private function earliestActiveTrialEnd(Subscription $subscription): ?Carbon
    {
        $earliest = null;

        foreach ($subscription->items as $item) {
            if ($item->status !== SubscriptionItemStatus::Active || $item->trial_ends_at === null) {
                continue;
            }

            if ($earliest === null || $item->trial_ends_at->lt($earliest)) {
                $earliest = $item->trial_ends_at->copy();
            }
        }

        return $earliest;
    }

    private function resolvePaymentMethod(Subscription $subscription): ?PaymentMethod
    {
        if ($subscription->collection_mode !== CollectionMode::Automatic) {
            return null;
        }

        return $subscription->paymentMethod;
    }

    private function addInterval(CarbonInterface $date, BillingInterval $interval, int $count): Carbon
    {
        $mutable = Carbon::instance($date);

        return match ($interval) {
            BillingInterval::Day => $mutable->addDays($count),
            BillingInterval::Week => $mutable->addWeeks($count),
            BillingInterval::Month => $mutable->addMonths($count),
            BillingInterval::Year => $mutable->addYears($count),
        };
    }
}
