<?php

namespace App\Actions\Subscriptions;

use App\Actions\Invoicing\CollectInvoice;
use App\Actions\Invoicing\CreateInvoice;
use App\Enums\BillingInterval;
use App\Enums\CollectionMode;
use App\Enums\InvoiceBillingReason;
use App\Enums\InvoiceLineKind;
use App\Enums\ScheduleEndBehavior;
use App\Enums\SubscriptionItemStatus;
use App\Enums\SubscriptionScheduleStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\TrialEndBehavior;
use App\Models\Invoice;
use App\Models\PaymentMethod;
use App\Models\Price;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\SubscriptionItem;
use App\Models\SubscriptionSchedule;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Advance a subscription across a subscription-schedule boundary
 * (schema.md §5) — the engine behind Pricing Journeys: at each boundary the
 * item's `current_schedule_step_id` advances to the next
 * `subscription_schedule_steps` row (dates already resolved at copy time —
 * no interval arithmetic here) and its `price_id`/`plan_id`/`product_id`
 * re-anchor to that step's price, so entitlements and reporting always
 * reflect what the customer is actually being billed for.
 *
 * A *free* trial is the degenerate single-boundary case: the item's step-0
 * price is ₦0, the schedule clock runs out, and the subscription converts
 * `trialing → active`. A ramp is the general case — the item steps through
 * boundaries until it lands on its terminal ("forever") step, at which
 * point the schedule finalizes: `end_behavior=release` collapses the item
 * back to flat, ordinary billing; `end_behavior=cancel` cancels the
 * subscription.
 *
 * Discount re-validation (schema.md §7, deliberately stricter than every
 * other price-change path in the app): a schedule step can move an item
 * onto a different plan entirely, so at every boundary an active discount
 * redemption is re-checked against the item's POST-step state — if it's no
 * longer eligible, it's permanently ended, not just skipped for one invoice.
 *
 * State threading (schema.md §5): while the next step is still free the
 * subscription stays `trialing`; the moment it lands on a paid step it
 * converts and bills. When a free trial ends with no payment method, the
 * subscription honours `trial_end_behavior` (cancel / pause / create_invoice).
 */
class AdvanceSubscriptionSchedule
{
    public function __construct(
        private readonly CreateInvoice $createInvoice,
        private readonly CollectInvoice $collectInvoice,
        private readonly RedeemDiscount $redeemDiscount,
    ) {
        //
    }

    public function handle(Subscription $subscription): ?Invoice
    {
        if (! $this->isDue($subscription)) {
            return null;
        }

        $subscription->loadMissing([
            'customer', 'items.price.product', 'items.currentScheduleStep.price', 'items.schedule',
            'paymentMethod', 'team',
        ]);

        /** @var Invoice|null $invoice */
        $invoice = DB::transaction(function () use ($subscription): ?Invoice {
            $now = Carbon::now();

            $pendingFinalizations = $this->advanceDueItems($subscription, $now);

            // A subsequent step can itself be a free trial — if any item is
            // still trialing after advancing, the subscription stays `trialing`
            // and simply re-anchors its clock to the next boundary.
            if ($this->stillTrialing($subscription)) {
                $this->reanchorTrialClock($subscription);

                return null;
            }

            $invoice = $this->settleAfterBoundary($subscription, $now);

            foreach ($pendingFinalizations as $pending) {
                $this->finalizeSchedule($pending['schedule'], $pending['item'], $subscription, $now);
            }

            return $invoice;
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
     * A subscription is due for schedule advancement when a free trial's
     * clock has run out, or when an active subscription still threading a
     * schedule has reached its current step's boundary
     * (`current_period_end`).
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
                && $this->hasItemOnSchedule($subscription);
        }

        return false;
    }

    private function hasItemOnSchedule(Subscription $subscription): bool
    {
        return $subscription->items->contains(
            fn (SubscriptionItem $item): bool => $item->status === SubscriptionItemStatus::Active
                && $item->isOnSchedule(),
        );
    }

    /**
     * Advance every item sitting on a boundary: a scheduled item steps to
     * its next step (re-arming its trial clock if that step is itself
     * free); a simple trial item simply clears its trial clock.
     *
     * @return list<array{schedule: SubscriptionSchedule, item: SubscriptionItem}>
     */
    private function advanceDueItems(Subscription $subscription, Carbon $now): array
    {
        $pendingFinalizations = [];

        foreach ($subscription->items as $item) {
            if ($item->status !== SubscriptionItemStatus::Active) {
                continue;
            }

            if ($item->isOnSchedule()) {
                $schedule = $this->stepItemToNextStep($subscription, $item, $now);

                if ($schedule !== null) {
                    $pendingFinalizations[] = ['schedule' => $schedule, 'item' => $item];
                }

                continue;
            }

            // Simple trial (or a steady, non-scheduled item) whose clock has elapsed.
            if ($item->trial_ends_at !== null && $item->trial_ends_at->lte($now)) {
                $item->forceFill(['trial_ends_at' => null])->save();
            }
        }

        return $pendingFinalizations;
    }

    /**
     * Step an item to its schedule's next step, re-anchoring price/plan/
     * product (and quantity) to that step and re-validating any active
     * discount redemption against the new state. Returns the schedule when
     * the item just landed on its terminal step (caller finalizes it after
     * billing), null otherwise.
     */
    private function stepItemToNextStep(Subscription $subscription, SubscriptionItem $item, Carbon $now): ?SubscriptionSchedule
    {
        $schedule = $item->schedule;
        $currentStep = $item->currentScheduleStep;
        $nextSequence = (int) $currentStep->sequence + 1;

        $nextStep = $schedule->steps()->where('sequence', $nextSequence)->with('price')->first();

        if ($nextStep === null) {
            return null;
        }

        $nextIsFree = ! $nextStep->isTerminal() && ($nextStep->price->unit_amount ?? 0) === 0;

        $item->forceFill([
            'price_id' => $nextStep->price_id,
            'plan_id' => $nextStep->price->plan_id,
            'product_id' => $nextStep->price->product_id,
            'quantity' => $nextStep->quantity,
            'current_schedule_step_id' => $nextStep->id,
            'trial_ends_at' => $nextIsFree ? $nextStep->ends_at : null,
        ])->save();

        // Mutating price_id/plan_id/product_id does NOT refresh Eloquent's
        // already-loaded `price`/`plan`/`product` relations — every
        // downstream read (boundary invoice lines, the anchor price for the
        // next period end) must see the item's NEW price, not the stale
        // cached one from before this boundary.
        $item->unsetRelation('price');
        $item->unsetRelation('plan');
        $item->unsetRelation('product');

        $this->reValidateDiscount($subscription);

        return $nextStep->isTerminal() ? $schedule : null;
    }

    /**
     * A schedule step can move an item onto a different plan entirely — a
     * discount redeemed against the old plan may no longer qualify. Every
     * other price-change path in the app treats eligibility as a
     * signup-time-only gate (schema.md §7); schedules deliberately don't,
     * since re-anchoring plan/product at each boundary already makes the
     * "what is this customer actually on" question a live one.
     */
    private function reValidateDiscount(Subscription $subscription): void
    {
        $redemption = $subscription->activeDiscountRedemption();

        if ($redemption === null) {
            return;
        }

        $items = $subscription->items()->get();

        if (! $redemption->discount->isRedeemableBySubscriptionItems($items, $subscription->currency)) {
            $this->redeemDiscount->remove($subscription);
        }
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
        // no way to charge. An already-active schedule just bills its next
        // step and lets normal dunning handle a decline.
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
        // A trialing subscription converts; an already-active schedule stays active.
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
        $anchorPrice = $anchorItem?->price;

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
     * The boundary invoice bundles every active item at its now-current
     * price — plan and add-ons alike (GAP-4: add-ons ride the plan item's
     * trial and are first invoiced here, together).
     *
     * @return list<array{subscriptionItem: SubscriptionItem, price: Price, product: Product, kind: InvoiceLineKind, description: string, unitAmount: int, quantity: int}>
     */
    private function buildBoundaryLines(Subscription $subscription): array
    {
        return array_values($subscription->items
            ->filter(fn (SubscriptionItem $item): bool => $item->status === SubscriptionItemStatus::Active)
            ->map(function (SubscriptionItem $item): array {
                $price = $item->price;
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
            ->all());
    }

    /**
     * Finalize a schedule that just landed on its terminal step: `release`
     * collapses the item back to flat billing (nothing left to track —
     * `current_schedule_step_id` clears and every future biller just reads
     * `price_id` like any ordinary item); `cancel` cancels the subscription.
     * Runs after the boundary invoice bills, so a `release` schedule's
     * terminal-step invoice (already at the right price) settles normally
     * first.
     */
    private function finalizeSchedule(SubscriptionSchedule $schedule, SubscriptionItem $item, Subscription $subscription, Carbon $now): void
    {
        if ($schedule->end_behavior === ScheduleEndBehavior::Cancel) {
            $subscription->apply('cancel');
            $schedule->update(['status' => SubscriptionScheduleStatus::Canceled, 'completed_at' => $now]);

            return;
        }

        $item->forceFill(['current_schedule_step_id' => null])->save();
        $schedule->update(['status' => SubscriptionScheduleStatus::Completed, 'completed_at' => $now]);
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
