<?php

namespace App\Actions\Subscriptions;

use App\Actions\Invoicing\CollectInvoice;
use App\Actions\Invoicing\CreateInvoice;
use App\Enums\CollectionMode;
use App\Enums\InvoiceBillingReason;
use App\Enums\InvoiceLineKind;
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
use InvalidArgumentException;

/**
 * Change a subscription item's plan or quantity and invoice the prorated delta.
 */
class UpdateSubscriptionItem
{
    public function __construct(
        private readonly CreateInvoice $createInvoice,
        private readonly CollectInvoice $collectInvoice,
    ) {
        //
    }

    public function handle(
        Subscription $subscription,
        SubscriptionItem $item,
        ?int $quantity = null,
        ?int $priceId = null,
    ): ?Invoice {
        if ($item->subscription_id !== $subscription->id) {
            throw new InvalidArgumentException('The item does not belong to this subscription.');
        }

        if (! in_array($subscription->status, [SubscriptionStatus::Active, SubscriptionStatus::PastDue], true)) {
            throw new InvalidArgumentException('Only active subscriptions can be updated.');
        }

        $newQuantity = $quantity ?? $item->quantity;
        $newPrice = $priceId !== null
            ? $subscription->team->prices()->with('product')->findOrFail($priceId)
            : $item->price;

        if ($newQuantity === $item->quantity && $newPrice->id === $item->price_id) {
            throw new InvalidArgumentException('Provide a new quantity or price to update the item.');
        }

        if ($newPrice->currency !== $subscription->currency) {
            throw new InvalidArgumentException('The new price must match the subscription currency.');
        }

        if ($newPrice->billing_interval === null) {
            throw new InvalidArgumentException('Only recurring prices can be subscribed to.');
        }

        $this->assertSingleCadence($subscription, $item, $newPrice);

        $subscription->loadMissing(['customer', 'paymentMethod', 'team']);
        $item->loadMissing('price.product');

        $oldPrice = $item->price;
        $oldQuantity = $item->quantity;
        $fraction = $this->prorationFraction($subscription);
        $periodStart = Carbon::instance($subscription->current_period_start ?? now());
        $periodEnd = Carbon::instance($subscription->current_period_end ?? now());

        /** @var Invoice|null $invoice */
        $invoice = DB::transaction(function () use (
            $subscription,
            $item,
            $newQuantity,
            $newPrice,
            $oldPrice,
            $oldQuantity,
            $fraction,
            $periodStart,
            $periodEnd,
        ): ?Invoice {
            $item->forceFill([
                'quantity' => $newQuantity,
                'price_id' => $newPrice->id,
                'product_id' => $newPrice->product_id,
            ])->save();

            $lines = $this->buildProrationLines(
                item: $item,
                oldPrice: $oldPrice,
                oldQuantity: $oldQuantity,
                newPrice: $newPrice,
                newQuantity: $newQuantity,
                fraction: $fraction,
                periodStart: $periodStart,
                periodEnd: $periodEnd,
            );

            if ($lines === []) {
                return null;
            }

            $dueAt = $subscription->collection_mode === CollectionMode::Manual
                ? Carbon::now()->addDays(7)
                : null;

            return $this->createInvoice->handle(
                team: $subscription->team,
                customer: $subscription->customer,
                billingReason: InvoiceBillingReason::SubscriptionUpdate,
                collectionMode: $subscription->collection_mode,
                lines: $lines,
                subscription: $subscription,
                dueAt: $dueAt,
            );
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
     * One renewal clock per subscription (schema.md §6, GAP-5): a price swap
     * may not introduce a `billing_interval`/`billing_frequency` that differs
     * from the subscription's other active items. A customer who needs a
     * second cadence holds a second subscription (ADV-05).
     */
    private function assertSingleCadence(Subscription $subscription, SubscriptionItem $item, Price $newPrice): void
    {
        $siblings = $subscription->activeItems()
            ->whereKeyNot($item->id)
            ->with('price')
            ->get();

        foreach ($siblings as $sibling) {
            if ($sibling->price->billing_interval !== $newPrice->billing_interval
                || $sibling->price->billing_frequency !== $newPrice->billing_frequency) {
                throw new InvalidArgumentException(
                    'All items on a subscription must share one billing cadence — use a separate subscription for a different interval.'
                );
            }
        }
    }

    private function prorationFraction(Subscription $subscription): float
    {
        $periodStart = $subscription->current_period_start;
        $periodEnd = $subscription->current_period_end;

        if ($periodStart === null || $periodEnd === null || $periodEnd->lte(now())) {
            return 0.0;
        }

        $totalSeconds = max(1, $periodStart->diffInSeconds($periodEnd));
        $remainingSeconds = max(0, now()->diffInSeconds($periodEnd));

        return min(1.0, $remainingSeconds / $totalSeconds);
    }

    /**
     * @return list<array{subscriptionItem: SubscriptionItem, price: Price, product: Product, kind: InvoiceLineKind, description: string, unitAmount: int, quantity: int, periodStart: CarbonInterface, periodEnd: CarbonInterface, proration: bool}>
     */
    private function buildProrationLines(
        SubscriptionItem $item,
        Price $oldPrice,
        int $oldQuantity,
        Price $newPrice,
        int $newQuantity,
        float $fraction,
        CarbonInterface $periodStart,
        CarbonInterface $periodEnd,
    ): array {
        if ($fraction <= 0.0) {
            return [];
        }

        $lines = [];
        $oldAmount = (int) round(($oldPrice->unit_amount ?? 0) * $oldQuantity * $fraction);
        $newAmount = (int) round(($newPrice->unit_amount ?? 0) * $newQuantity * $fraction);

        if ($oldAmount !== 0) {
            $lines[] = $this->prorationLine(
                item: $item,
                price: $oldPrice,
                product: $oldPrice->product,
                description: 'Unused time on '.$oldPrice->product->name.' · '.$oldPrice->toPickerLabel(),
                amount: -$oldAmount,
                periodStart: $periodStart,
                periodEnd: $periodEnd,
            );
        }

        if ($newAmount !== 0) {
            $lines[] = $this->prorationLine(
                item: $item,
                price: $newPrice,
                product: $newPrice->product,
                description: 'Remaining time on '.$newPrice->product->name.' · '.$newPrice->toPickerLabel(),
                amount: $newAmount,
                periodStart: $periodStart,
                periodEnd: $periodEnd,
            );
        }

        $net = array_sum(array_map(
            fn (array $line): int => $line['unitAmount'] * $line['quantity'],
            $lines,
        ));

        return $net === 0 ? [] : $lines;
    }

    /**
     * @return array{subscriptionItem: SubscriptionItem, price: Price, product: Product, kind: InvoiceLineKind, description: string, unitAmount: int, quantity: int, periodStart: CarbonInterface, periodEnd: CarbonInterface, proration: bool}
     */
    private function prorationLine(
        SubscriptionItem $item,
        Price $price,
        Product $product,
        string $description,
        int $amount,
        CarbonInterface $periodStart,
        CarbonInterface $periodEnd,
    ): array {
        return [
            'subscriptionItem' => $item,
            'price' => $price,
            'product' => $product,
            'kind' => InvoiceLineKind::Proration,
            'description' => $description,
            'unitAmount' => $amount,
            'quantity' => 1,
            'periodStart' => $periodStart,
            'periodEnd' => $periodEnd,
            'proration' => true,
        ];
    }

    private function resolvePaymentMethod(Subscription $subscription): ?PaymentMethod
    {
        if ($subscription->collection_mode !== CollectionMode::Automatic) {
            return null;
        }

        return $subscription->paymentMethod;
    }
}
