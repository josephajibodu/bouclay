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
 * Generate a renewal invoice at period end and hand collection to the engine.
 */
class RenewSubscription
{
    public function __construct(
        private readonly CreateInvoice $createInvoice,
        private readonly CollectInvoice $collectInvoice,
    ) {
        //
    }

    public function handle(Subscription $subscription): ?Invoice
    {
        if ($subscription->status !== SubscriptionStatus::Active) {
            return null;
        }

        if ($subscription->current_period_end === null || $subscription->current_period_end->isFuture()) {
            return null;
        }

        $subscription->loadMissing(['customer', 'items.price.product', 'items.currentScheduleStep', 'paymentMethod', 'team']);

        // A subscription still stepping through a Pricing Journey schedule is
        // owned by `subscriptions:advance-schedule` until it reaches its
        // terminal step — don't renew it out from under the schedule.
        if ($this->hasItemOnSchedule($subscription)) {
            return null;
        }

        /** @var Invoice|null $invoice */
        $invoice = DB::transaction(function () use ($subscription): ?Invoice {
            $lines = $this->buildRenewalLines($subscription);

            if ($lines === []) {
                return null;
            }

            $periodStart = $subscription->current_period_end->copy();
            $anchorItem = $subscription->items
                ->first(fn (SubscriptionItem $item): bool => $item->status === SubscriptionItemStatus::Active);
            $anchorPrice = $anchorItem?->price;
            $periodEnd = $anchorPrice !== null
                ? $this->addInterval(
                    $periodStart->copy(),
                    $anchorPrice->billing_interval ?? BillingInterval::Month,
                    $anchorPrice->billing_frequency,
                )
                : null;

            // Apply the discount to this cycle while it still has intervals
            // left, then decrement it (schema.md §7, GAP-1).
            $redemption = $subscription->activeDiscountRedemption();

            $invoice = $this->createInvoice->handle(
                team: $subscription->team,
                customer: $subscription->customer,
                billingReason: InvoiceBillingReason::SubscriptionCycle,
                collectionMode: $subscription->collection_mode,
                lines: $lines,
                subscription: $subscription,
                dueAt: $subscription->collection_mode === CollectionMode::Manual
                    ? $periodStart->copy()->addDays(7)
                    : null,
                discount: $redemption?->discount,
            );

            if ($redemption !== null && $invoice->discount_total > 0) {
                $redemption->recordApplied();
            }

            $subscription->forceFill([
                'current_period_start' => $periodStart,
                'current_period_end' => $periodEnd,
            ])->save();

            return $invoice;
        });

        if ($invoice === null) {
            return null;
        }

        $paymentMethod = $this->resolvePaymentMethod($subscription);

        $this->collectInvoice->handle($subscription->team, $invoice, $paymentMethod);

        return $invoice;
    }

    /**
     * @return list<array{subscriptionItem: SubscriptionItem, price: Price, product: Product, kind: InvoiceLineKind, description: string, unitAmount: int, quantity: int}>
     */
    private function buildRenewalLines(Subscription $subscription): array
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
                    // Item kinds map 1:1 onto line kinds (plan / addon).
                    'kind' => InvoiceLineKind::from($item->kind->value),
                    'description' => $product->name.' · '.$price->toPickerLabel(),
                    'unitAmount' => $price->unit_amount ?? 0,
                    'quantity' => $item->quantity,
                ];
            })
            ->all());
    }

    private function resolvePaymentMethod(Subscription $subscription): ?PaymentMethod
    {
        if ($subscription->collection_mode !== CollectionMode::Automatic) {
            return null;
        }

        return $subscription->paymentMethod;
    }

    private function hasItemOnSchedule(Subscription $subscription): bool
    {
        return $subscription->items->contains(
            fn (SubscriptionItem $item): bool => $item->status === SubscriptionItemStatus::Active
                && $item->isOnSchedule(),
        );
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
