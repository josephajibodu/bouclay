<?php

namespace App\Actions\Subscriptions;

use App\Actions\Invoicing\CollectInvoice;
use App\Actions\Invoicing\CreateInvoice;
use App\Actions\Webhooks\EmitOutboundEvent;
use App\Enums\BillingInterval;
use App\Enums\CollectionMode;
use App\Enums\InvoiceBillingReason;
use App\Enums\InvoiceLineKind;
use App\Enums\OutboundEventType;
use App\Enums\SubscriptionItemKind;
use App\Enums\SubscriptionItemStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\TrialEndBehavior;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Price;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\SubscriptionItem;
use App\Models\Team;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Builds a subscription from a customer + a list of `{price_id, quantity}`
 * line items + a collection mode. This is the single seam the dashboard
 * controller and the API both call so the two never diverge
 * (SUBSCRIPTIONS_DESIGN §7.4, §17.11).
 *
 * V2 catalog rules (schema.md §3/§6): only plan-bearing recurring prices are
 * subscribable — `subscription_items.plan_id` is NOT NULL, so a plan-less
 * one-time price is rejected here. The first item is the base `plan` item;
 * further items are `addon`s. Trial anchoring off `prices.trial_*` and phase
 * progression land in V2-2 — until then every subscription bills at signup.
 */
class CreateSubscription
{
    public function __construct(
        private readonly CreateInvoice $createInvoice,
        private readonly CollectInvoice $collectInvoice,
        private readonly EmitOutboundEvent $emitOutboundEvent,
    ) {
        //
    }

    /**
     * @param  array<string, mixed>  $data  the validated request body (customer_id,
     *                                      collection_mode, items[], payment_method_id?,
     *                                      trial_end_behavior?)
     */
    public function handle(Team $team, array $data): Subscription
    {
        /** @var Customer $customer */
        $customer = $team->customers()->findOrFail($data['customer_id']);

        $collectionMode = CollectionMode::from((string) $data['collection_mode']);
        $currency = $customer->currency ?: $team->default_currency;

        /** @var array<int, array<string, mixed>> $items */
        $items = $data['items'] ?? [];
        $lines = $this->resolveLines($team, $items);
        $this->assertCurrency($lines, $currency);
        $this->assertSingleCadence($lines);

        $paymentMethodId = isset($data['payment_method_id']) ? (int) $data['payment_method_id'] : null;
        if ($paymentMethodId !== null) {
            // Only a card belonging to this customer may be attached.
            $customer->paymentMethods()->findOrFail($paymentMethodId);
        }

        /** @var array{0: Subscription, 1: Invoice|null} $result */
        $result = DB::transaction(function () use ($team, $customer, $collectionMode, $currency, $lines, $paymentMethodId, $data) {
            $now = Carbon::now();

            $subscription = $team->subscriptions()->create([
                'customer_id' => $customer->id,
                'type' => 'default',
                // Provisional — overwritten below once items exist and we know
                // the branch. Every real transition still goes through apply().
                'status' => SubscriptionStatus::Incomplete,
                'currency' => $currency,
                'collection_mode' => $collectionMode,
                'payment_method_id' => $paymentMethodId,
                'trial_end_behavior' => TrialEndBehavior::tryFrom((string) ($data['trial_end_behavior'] ?? ''))
                    ?? TrialEndBehavior::CreateInvoice,
                'billing_cycle_anchor_on_trial_end' => 'now',
                'current_period_start' => $now,
            ]);

            /** @var list<array{item: SubscriptionItem, price: Price, quantity: int}> $billedItems */
            $billedItems = [];

            foreach ($lines as $index => $line) {
                $item = $subscription->items()->create([
                    'price_id' => $line['price']->id,
                    'plan_id' => $line['price']->plan_id,
                    'product_id' => $line['price']->product_id,
                    'kind' => $index === 0 ? SubscriptionItemKind::Plan : SubscriptionItemKind::Addon,
                    'quantity' => $line['quantity'],
                    'status' => SubscriptionItemStatus::Active,
                ]);

                $billedItems[] = ['item' => $item, 'price' => $line['price'], 'quantity' => $line['quantity']];
            }

            $invoice = $this->settleInitialState($team, $subscription, $customer, $collectionMode, $billedItems, $now);

            return [$subscription, $invoice];
        });

        [$subscription, $invoice] = $result;

        // The real Nomba charge is real external I/O with real money moving —
        // it must run only after the subscription/items/invoice have safely
        // committed, never inside the transaction above. A charge that
        // succeeded but then got rolled back with it would mean money moved
        // with no Bouclay record of it.
        if ($invoice !== null) {
            $paymentMethod = $paymentMethodId !== null
                ? $customer->paymentMethods()->findOrFail($paymentMethodId)
                : null;

            $this->collectInvoice->handle($team, $invoice, $paymentMethod);
        }

        $subscription->loadMissing('customer');

        $this->emitOutboundEvent->handle(
            $team,
            OutboundEventType::SubscriptionCreated,
            ['object' => $subscription->toWebhookObject()],
        );

        return $subscription;
    }

    /**
     * Turn raw item inputs into resolved lines. A subscription bills
     * plan-bearing recurring prices only (schema.md §3 constraint).
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array{price: Price, quantity: int}>
     */
    private function resolveLines(Team $team, array $items): array
    {
        if ($items === []) {
            throw new InvalidArgumentException('A subscription needs at least one line item.');
        }

        $lines = [];

        foreach ($items as $item) {
            $quantity = max(1, (int) ($item['quantity'] ?? 1));

            /** @var Price $price */
            $price = $team->prices()->findOrFail((int) $item['price_id']);

            if ($price->billing_interval === null) {
                throw new InvalidArgumentException('Only recurring prices can be subscribed to.');
            }

            if ($price->plan_id === null) {
                throw new InvalidArgumentException(
                    "Price {$price->public_id} does not belong to a plan — only plan-bearing prices can be subscribed to."
                );
            }

            // The one shared purchasability rule (V2-1): archived or
            // phase-only prices, and prices under a draft/archived plan,
            // are not offered to new subscriptions.
            if (! $price->isPurchasableForNewSubscriptions()) {
                throw new InvalidArgumentException(
                    "Price {$price->public_id} is not currently purchasable — its plan may be draft or archived."
                );
            }

            $lines[] = ['price' => $price, 'quantity' => $quantity];
        }

        // A plan can only appear once on a subscription — two lines for the
        // same plan describe the same charge twice.
        $planIds = array_map(fn (array $line): int => (int) $line['price']->plan_id, $lines);
        if (count($planIds) !== count(array_unique($planIds))) {
            throw new InvalidArgumentException('Each plan can only appear once on a subscription.');
        }

        return $lines;
    }

    /**
     * A subscription is single-currency for life — reject a mixed cart.
     *
     * @param  array<int, array{price: Price, quantity: int}>  $lines
     */
    private function assertCurrency(array $lines, string $currency): void
    {
        foreach ($lines as $line) {
            if ($line['price']->currency !== $currency) {
                throw new InvalidArgumentException(
                    "All prices must be in {$currency}; {$line['price']->public_id} is {$line['price']->currency}."
                );
            }
        }
    }

    /**
     * One renewal clock per subscription (schema.md §6, GAP-5): every
     * recurring item must share the same billing_interval + billing_frequency.
     * A customer needing monthly AND annual charges holds two subscriptions.
     *
     * @param  array<int, array{price: Price, quantity: int}>  $lines
     */
    private function assertSingleCadence(array $lines): void
    {
        $first = $lines[0]['price'];

        foreach ($lines as $line) {
            if ($line['price']->billing_interval !== $first->billing_interval
                || $line['price']->billing_frequency !== $first->billing_frequency) {
                throw new InvalidArgumentException(
                    'All items on a subscription must share one billing cadence — use a separate subscription for a different interval.'
                );
            }
        }
    }

    /**
     * Choose the initial billing clock and create the signup invoice
     * (SUBSCRIPTIONS_DESIGN §4). Runs inside the creation transaction —
     * everything here is a local write, no external calls. Returns the
     * invoice so the caller can charge it after commit.
     *
     * @param  list<array{item: SubscriptionItem, price: Price, quantity: int}>  $billedItems
     */
    private function settleInitialState(
        Team $team,
        Subscription $subscription,
        Customer $customer,
        CollectionMode $collectionMode,
        array $billedItems,
        Carbon $now,
    ): ?Invoice {
        // The next charge is one interval away — all items share one cadence
        // (asserted above), so the first price anchors the clock.
        $firstBilledPrice = $billedItems[0]['price'] ?? null;
        $periodEnd = $firstBilledPrice !== null
            ? $this->addInterval($now->copy(), $firstBilledPrice->billing_interval ?? BillingInterval::Month, $firstBilledPrice->billing_frequency)
            : null;

        $subscription->current_period_end = $periodEnd;
        $subscription->save();

        if ($billedItems === []) {
            return null;
        }

        return $this->createInvoice->handle(
            team: $team,
            customer: $customer,
            billingReason: InvoiceBillingReason::SubscriptionCreate,
            collectionMode: $collectionMode,
            lines: $this->buildInvoiceLines($billedItems),
            subscription: $subscription,
            dueAt: $collectionMode === CollectionMode::Manual ? $now->copy()->addDays(7) : null,
        );
    }

    /**
     * Turn the billed subscription items into invoice-line inputs
     * ({@see CreateInvoice}) — one line per item, its kind mirroring the
     * item's (plan / addon).
     *
     * @param  list<array{item: SubscriptionItem, price: Price, quantity: int}>  $billedItems
     * @return list<array{subscriptionItem: SubscriptionItem, price: Price, product: Product, kind: InvoiceLineKind, description: string, unitAmount: int, quantity: int}>
     */
    private function buildInvoiceLines(array $billedItems): array
    {
        return array_map(fn (array $billed): array => [
            'subscriptionItem' => $billed['item'],
            'price' => $billed['price'],
            'product' => $billed['price']->product,
            'kind' => InvoiceLineKind::from($billed['item']->kind->value),
            'description' => $billed['price']->product->name.' · '.$billed['price']->toPickerLabel(),
            'unitAmount' => $billed['price']->unit_amount ?? 0,
            'quantity' => $billed['quantity'],
        ], $billedItems);
    }

    /**
     * Add N billing intervals to a date.
     */
    private function addInterval(Carbon $date, BillingInterval $interval, int $count): Carbon
    {
        return match ($interval) {
            BillingInterval::Day => $date->addDays($count),
            BillingInterval::Week => $date->addWeeks($count),
            BillingInterval::Month => $date->addMonths($count),
            BillingInterval::Year => $date->addYears($count),
        };
    }
}
