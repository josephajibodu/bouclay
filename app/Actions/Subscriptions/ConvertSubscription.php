<?php

namespace App\Actions\Subscriptions;

use App\Actions\Invoicing\CollectInvoice;
use App\Actions\Invoicing\CreateInvoice;
use App\Enums\BillingInterval;
use App\Enums\CollectionMode;
use App\Enums\InvoiceBillingReason;
use App\Enums\InvoiceLineKind;
use App\Enums\SubscriptionItemStatus;
use App\Enums\SubscriptionItemTrialStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\TrialEndBehavior;
use App\Models\Invoice;
use App\Models\PaymentMethod;
use App\Models\Price;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\SubscriptionItem;
use App\Models\SubscriptionItemTrial;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Convert expired item trials to their transition prices and bill the first
 * regular cycle when a free trial ends.
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
        $dueTrials = $this->dueTrials($subscription);

        if ($dueTrials->isEmpty()) {
            return null;
        }

        $subscription->loadMissing(['customer', 'items.price.product', 'paymentMethod', 'team']);

        $wasTrialing = $subscription->status === SubscriptionStatus::Trialing;

        /** @var Invoice|null $invoice */
        $invoice = DB::transaction(function () use ($subscription, $dueTrials, $wasTrialing): ?Invoice {
            foreach ($dueTrials as $trial) {
                $this->convertTrialItem($trial);
            }

            $this->recomputeTrialEndsAt($subscription);

            if (! $wasTrialing) {
                return null;
            }

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
     * @return Collection<int, SubscriptionItemTrial>
     */
    private function dueTrials(Subscription $subscription): Collection
    {
        return SubscriptionItemTrial::query()
            ->where('status', SubscriptionItemTrialStatus::Active)
            ->where('ends_at', '<=', now())
            ->whereHas('subscriptionItem', fn ($query) => $query->where('subscription_id', $subscription->id))
            ->with(['subscriptionItem', 'transitionPrice.product'])
            ->orderBy('id')
            ->get();
    }

    private function convertTrialItem(SubscriptionItemTrial $trial): void
    {
        $item = $trial->subscriptionItem;
        $transitionPrice = $trial->transitionPrice;

        $item->forceFill([
            'price_id' => $transitionPrice->id,
            'product_id' => $transitionPrice->product_id,
        ])->save();

        $trial->forceFill([
            'status' => SubscriptionItemTrialStatus::Converted,
            'converted_at' => Carbon::now(),
        ])->save();
    }

    private function recomputeTrialEndsAt(Subscription $subscription): void
    {
        $earliestEnd = SubscriptionItemTrial::query()
            ->where('status', SubscriptionItemTrialStatus::Active)
            ->whereHas('subscriptionItem', fn ($query) => $query->where('subscription_id', $subscription->id))
            ->min('ends_at');

        $subscription->forceFill([
            'trial_ends_at' => $earliestEnd !== null ? Carbon::parse((string) $earliestEnd) : null,
        ])->save();
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
                    'kind' => InvoiceLineKind::Subscription,
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
