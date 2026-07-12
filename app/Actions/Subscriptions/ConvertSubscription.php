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
use App\Models\Product;
use App\Models\Subscription;
use App\Models\SubscriptionItem;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Convert a trialing subscription whose trial clock has run out: swap it to
 * `active` and bill the first regular cycle (schema.md §5).
 *
 * V2 trials are snapshotted onto `subscription_items.trial_ends_at` with the
 * subscription's `trial_ends_at` as the denormalised earliest-end clock. The
 * item keeps its price — a simple trial is the degenerate case where the
 * trial phase and the regular phase share one price row. Phase progression
 * (`price_phases` / `current_phase_sequence`) generalizes this in V2-2.
 */
class ConvertSubscription
{
    public function __construct(
        private readonly CreateInvoice $createInvoice,
        private readonly CollectInvoice $collectInvoice,
    ) {
        //
    }

    public function handle(Subscription $subscription): ?Invoice
    {
        if ($subscription->status !== SubscriptionStatus::Trialing) {
            return null;
        }

        if ($subscription->trial_ends_at === null || $subscription->trial_ends_at->isFuture()) {
            return null;
        }

        $subscription->loadMissing(['customer', 'items.price.product', 'paymentMethod', 'team']);

        /** @var Invoice|null $invoice */
        $invoice = DB::transaction(function () use ($subscription): ?Invoice {
            $this->expireDueItemTrials($subscription);

            return $this->finalizeFreeTrialConversion($subscription);
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
     * Clear the item-level trial clocks that have run out and recompute the
     * subscription's denormalised `trial_ends_at` (earliest active item
     * trial end, or null when none remain).
     */
    private function expireDueItemTrials(Subscription $subscription): void
    {
        $now = Carbon::now();
        $remaining = null;

        foreach ($subscription->items as $item) {
            if ($item->trial_ends_at === null) {
                continue;
            }

            if ($item->trial_ends_at->lte($now)) {
                $item->forceFill(['trial_ends_at' => null])->save();

                continue;
            }

            if ($remaining === null || $item->trial_ends_at->lt($remaining)) {
                $remaining = $item->trial_ends_at;
            }
        }

        $subscription->forceFill(['trial_ends_at' => $remaining])->save();
    }

    private function finalizeFreeTrialConversion(Subscription $subscription): ?Invoice
    {
        if (! $this->shouldBillAfterConversion($subscription)) {
            match ($subscription->trial_end_behavior) {
                TrialEndBehavior::Cancel => $subscription->apply('cancel'),
                TrialEndBehavior::Pause => $subscription->apply('pause'),
                TrialEndBehavior::CreateInvoice => $this->startBillingAfterTrial($subscription),
            };

            return null;
        }

        return $this->startBillingAfterTrial($subscription);
    }

    private function shouldBillAfterConversion(Subscription $subscription): bool
    {
        if ($subscription->payment_method_id !== null) {
            return true;
        }

        return $subscription->trial_end_behavior === TrialEndBehavior::CreateInvoice;
    }

    private function startBillingAfterTrial(Subscription $subscription): ?Invoice
    {
        $subscription->apply('convert');
        $this->resetBillingPeriod($subscription);

        $lines = $this->buildConversionLines($subscription);

        if ($lines === []) {
            return null;
        }

        $dueAt = $subscription->collection_mode === CollectionMode::Manual
            ? Carbon::now()->addDays(7)
            : null;

        return $this->createInvoice->handle(
            team: $subscription->team,
            customer: $subscription->customer,
            billingReason: InvoiceBillingReason::SubscriptionCreate,
            collectionMode: $subscription->collection_mode,
            lines: $lines,
            subscription: $subscription,
            dueAt: $dueAt,
        );
    }

    private function resetBillingPeriod(Subscription $subscription): void
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
            : Carbon::now();

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
     * The conversion invoice bundles every active item — plan and add-ons
     * alike (GAP-4: add-ons ride the plan item's trial and are first
     * invoiced here, together).
     *
     * @return list<array{subscriptionItem: SubscriptionItem, price: Price, product: Product, kind: InvoiceLineKind, description: string, unitAmount: int, quantity: int}>
     */
    private function buildConversionLines(Subscription $subscription): array
    {
        return $subscription->items
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
            ->values()
            ->all();
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
