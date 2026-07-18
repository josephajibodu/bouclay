<?php

namespace App\Actions\Subscriptions;

use App\Actions\Invoicing\CollectInvoice;
use App\Actions\Invoicing\CreateInvoice;
use App\Enums\CollectionMode;
use App\Enums\InvoiceBillingReason;
use App\Enums\InvoiceLineKind;
use App\Enums\ProrationBehavior;
use App\Enums\ScheduledChangeAction;
use App\Enums\SubscriptionScheduleStatus;
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
 * Change a subscription item's plan, quantity, or presence, applying the
 * locked mid-cycle policy (schema.md §6, GAP-2/3/6).
 *
 * `proration_behavior` (a request parameter, never a column) governs when a
 * change lands and whether it prorates: `always` prorates + charges the delta
 * now, `none` applies now and bills nothing, `next_cycle` defers to the next
 * renewal via a scheduled `update` row. Defaults are policy — increases →
 * `always`, decreases/removals → `next_cycle` (MVP has no credit balance to
 * hold a mid-cycle credit). Any change while `trialing` applies immediately
 * with no proration (no money has moved; the conversion invoice reflects the
 * final composition).
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
        ?ProrationBehavior $prorationBehavior = null,
        bool $remove = false,
    ): ?Invoice {
        if ($item->subscription_id !== $subscription->id) {
            throw new InvalidArgumentException('The item does not belong to this subscription.');
        }

        // One mechanism owns an item's future at a time (schema.md §5): a
        // deferred `scheduled_changes` update and an active Subscription
        // Schedule would otherwise race to change the same item at the same
        // boundary. Checked before the `$remove` branch too — removing an
        // item is just as much a conflict as a price/quantity change.
        if ($item->schedule()->where('status', SubscriptionScheduleStatus::Active)->exists()) {
            throw new InvalidArgumentException(
                'This item is on an active Pricing Journey schedule — modify or cancel the schedule instead of scheduling a separate change.'
            );
        }

        // Removing an add-on takes effect at the next renewal — no mid-cycle
        // credit in MVP (GAP-3). It's a deferred `update` with `remove:true`.
        if ($remove) {
            $this->scheduleUpdate($subscription, ['subscription_item_id' => $item->id, 'remove' => true]);

            return null;
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

        // While trialing, no money has moved yet: apply immediately, never
        // prorate — the conversion invoice reflects the final composition (ADV-01).
        if ($subscription->status === SubscriptionStatus::Trialing) {
            $this->applyItemChange($item, $newPrice, $newQuantity);

            return null;
        }

        if (! in_array($subscription->status, [SubscriptionStatus::Active, SubscriptionStatus::PastDue], true)) {
            throw new InvalidArgumentException('Only active or trialing subscriptions can be updated.');
        }

        $behavior = $prorationBehavior ?? $this->defaultBehavior($item, $newPrice, $newQuantity);

        // Defer to next renewal: write the scheduled payload, touch nothing now.
        if ($behavior === ProrationBehavior::NextCycle) {
            $this->scheduleUpdate($subscription, $this->updatePayload($item, $newPrice, $newQuantity, $priceId, $quantity));

            return null;
        }

        // `none` applies immediately and bills nothing (support / goodwill edits).
        if ($behavior === ProrationBehavior::None) {
            $this->applyItemChange($item, $newPrice, $newQuantity);

            return null;
        }

        // `always` — prorate and charge the delta now.
        $subscription->loadMissing(['customer', 'paymentMethod', 'team']);
        $item->loadMissing('price.product');

        $oldPrice = $item->price;
        $oldQuantity = $item->quantity;
        $fraction = $this->prorationFraction($subscription);
        // The proration lines cover the *remaining* window — from the change
        // moment to period end (SIM-02: day 12 → day 30).
        $periodStart = Carbon::now();
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
            $this->applyItemChange($item, $newPrice, $newQuantity);

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
     * Swap an item's plan / price / quantity in place (denormalising plan and
     * product alongside the price, schema.md §6).
     */
    private function applyItemChange(SubscriptionItem $item, Price $newPrice, int $newQuantity): void
    {
        $item->forceFill([
            'quantity' => $newQuantity,
            'price_id' => $newPrice->id,
            'plan_id' => $newPrice->plan_id,
            'product_id' => $newPrice->product_id,
        ])->save();
    }

    /**
     * The default proration behavior for a change (schema.md §6): an increase
     * in billed value prorates + charges now; a decrease defers to next cycle.
     * Equal value (a same-price swap) applies now — its proration nets to zero.
     */
    private function defaultBehavior(SubscriptionItem $item, Price $newPrice, int $newQuantity): ProrationBehavior
    {
        $oldValue = $item->quantity * ($item->price->unit_amount ?? 0);
        $newValue = $newQuantity * ($newPrice->unit_amount ?? 0);

        return $newValue >= $oldValue ? ProrationBehavior::Always : ProrationBehavior::NextCycle;
    }

    /**
     * The scheduled `update` payload for a deferred change — only the fields
     * that actually change (schema.md §6).
     *
     * @return array<string, mixed>
     */
    private function updatePayload(SubscriptionItem $item, Price $newPrice, int $newQuantity, ?int $priceId, ?int $quantity): array
    {
        $payload = ['subscription_item_id' => $item->id];

        if ($priceId !== null && $newPrice->id !== $item->price_id) {
            $payload['price_id'] = $newPrice->id;
            $payload['plan_id'] = $newPrice->plan_id;
        }

        if ($quantity !== null && $newQuantity !== $item->quantity) {
            $payload['quantity'] = $newQuantity;
        }

        return $payload;
    }

    /**
     * Queue a deferred item change for the next renewal boundary (schema.md §6,
     * GAP-2). One row per item change, all sharing `current_period_end`;
     * `subscriptions:apply-scheduled-changes` applies the payload there.
     *
     * @param  array<string, mixed>  $payload
     */
    private function scheduleUpdate(Subscription $subscription, array $payload): void
    {
        $subscription->scheduledChanges()->create([
            'action' => ScheduledChangeAction::Update,
            'effective_at' => $subscription->current_period_end ?? Carbon::now(),
            'payload' => $payload,
        ]);
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
