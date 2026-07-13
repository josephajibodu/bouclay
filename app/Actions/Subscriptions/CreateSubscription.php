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
use App\Enums\TrialUnit;
use App\Models\Customer;
use App\Models\Discount;
use App\Models\DiscountRedemption;
use App\Models\Invoice;
use App\Models\Price;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\SubscriptionItem;
use App\Models\Team;
use Carbon\CarbonInterface;
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
 * further items are `addon`s.
 *
 * Trial anchoring (schema.md §5, GAP-4, Stripe-style): the subscription's
 * trial anchors to the base plan item. A base item on a **free** trial
 * (`prices.trial_length`, or a phased price whose phase-0 charge is ₦0) starts
 * the subscription in `trialing` and bills **nothing** at day 0 — a no-trial
 * add-on rides the trial and is first invoiced at conversion. A base item with
 * a **paid** phase-0 (`charge_price.unit_amount > 0`) follows the normal
 * `incomplete → active` path, charging the phase-0 price now. `trial_ends_at`
 * is snapshotted onto each item and mirrored to the subscription (earliest
 * active). Conversion / phase progression lives in {@see AdvanceSubscriptionPhases}.
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
        $this->assertTrialEligibility($team, $customer, $lines);

        $discount = $this->resolveDiscount($team, $data);

        $paymentMethodId = isset($data['payment_method_id']) ? (int) $data['payment_method_id'] : null;
        if ($paymentMethodId !== null) {
            // Only a card belonging to this customer may be attached.
            $customer->paymentMethods()->findOrFail($paymentMethodId);
        }

        // The base plan item (first line) anchors the subscription's trial —
        // a free trial on it starts the whole subscription in `trialing` and
        // suppresses the day-0 invoice.
        $freeTrial = $this->startsFreeTrial($lines[0]['price']);

        /** @var array{0: Subscription, 1: Invoice|null} $result */
        $result = DB::transaction(function () use ($team, $customer, $collectionMode, $currency, $lines, $paymentMethodId, $data, $freeTrial, $discount) {
            $now = Carbon::now();

            $subscription = $team->subscriptions()->create([
                'customer_id' => $customer->id,
                'type' => 'default',
                // The initial status is a creation concern, not a transition:
                // a free trial opens in `trialing` (skips `incomplete`);
                // everything else opens `incomplete` and activates on the
                // day-0 charge via the collection engine.
                'status' => $freeTrial ? SubscriptionStatus::Trialing : SubscriptionStatus::Incomplete,
                'currency' => $currency,
                'collection_mode' => $collectionMode,
                'payment_method_id' => $paymentMethodId,
                'trial_end_behavior' => TrialEndBehavior::tryFrom((string) ($data['trial_end_behavior'] ?? ''))
                    ?? TrialEndBehavior::CreateInvoice,
                'billing_cycle_anchor_on_trial_end' => 'now',
                'current_period_start' => $now,
            ]);

            /** @var list<array{item: SubscriptionItem, price: Price, quantity: int}> $items */
            $items = [];
            $earliestTrialEnd = null;

            foreach ($lines as $index => $line) {
                $price = $line['price'];
                $trialEndsAt = $this->itemTrialEnd($price, $now);

                $item = $subscription->items()->create([
                    'price_id' => $price->id,
                    'plan_id' => $price->plan_id,
                    'product_id' => $price->product_id,
                    'kind' => $index === 0 ? SubscriptionItemKind::Plan : SubscriptionItemKind::Addon,
                    'quantity' => $line['quantity'],
                    'status' => SubscriptionItemStatus::Active,
                    'trial_ends_at' => $trialEndsAt,
                    // Only a phased price threads the phase counter; a simple
                    // trial never touches `price_phases` (schema.md §3).
                    'current_phase_sequence' => $price->phases->isNotEmpty() ? 0 : null,
                ]);

                if ($trialEndsAt !== null && ($earliestTrialEnd === null || $trialEndsAt->lt($earliestTrialEnd))) {
                    $earliestTrialEnd = $trialEndsAt;
                }

                // Durable anti-abuse row for a simple trial (schema.md §3) —
                // written the moment the trial starts so `trial_once_per_customer`
                // can be enforced on the next attempt.
                if ($price->trial_length !== null) {
                    $team->priceTrialRedemptions()->create([
                        'price_id' => $price->id,
                        'customer_id' => $customer->id,
                        'subscription_item_id' => $item->id,
                        'redeemed_at' => $now,
                    ]);
                }

                $items[] = ['item' => $item, 'price' => $price, 'quantity' => $line['quantity']];
            }

            $subscription->trial_ends_at = $earliestTrialEnd;

            // Redeem the discount now (schema.md §7): gate on eligibility,
            // snapshot the interval budget, attach it to the subscription. The
            // day-0 invoice (if any) applies + decrements it below; a free
            // trial's first application is deferred to conversion.
            $redemption = $this->redeemDiscount($subscription, $customer, $discount, $currency, $now);

            $invoice = $this->settleInitialState($team, $subscription, $customer, $collectionMode, $items, $now, $freeTrial, $discount, $redemption);

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
            $price = $team->prices()->with(['product', 'phases.chargePrice'])->findOrFail((int) $item['price_id']);

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
     * Enforce `trial_once_per_customer` (schema.md §3): a customer who has
     * already redeemed a price's simple trial can't take it again. Anti-abuse
     * is the one place we scope by `team_id` directly rather than inferring
     * the tenant through the price/customer join.
     *
     * @param  array<int, array{price: Price, quantity: int}>  $lines
     */
    private function assertTrialEligibility(Team $team, Customer $customer, array $lines): void
    {
        foreach ($lines as $line) {
            $price = $line['price'];

            if ($price->trial_length === null || ! $price->trial_once_per_customer) {
                continue;
            }

            $alreadyRedeemed = $team->priceTrialRedemptions()
                ->where('price_id', $price->id)
                ->where('customer_id', $customer->id)
                ->exists();

            if ($alreadyRedeemed) {
                throw new InvalidArgumentException(
                    "This customer has already used the trial on {$price->public_id} — trials are once per customer."
                );
            }
        }
    }

    /**
     * Whether a price starts the subscription on a **free** trial: a simple
     * trial (`trial_length`) is always free during its window, and a phased
     * price whose phase-0 charge is ₦0 is a free trial too. A phased price
     * whose phase-0 charge is > 0 is a *paid* trial — it bills at day 0 and
     * is not `trialing` (schema.md §5).
     */
    private function startsFreeTrial(Price $price): bool
    {
        if ($price->trial_length !== null) {
            return true;
        }

        $phaseZero = $price->phases->firstWhere('sequence', 0);

        return $phaseZero !== null && ($phaseZero->chargePrice->unit_amount ?? 0) === 0;
    }

    /**
     * The trial-window end snapshotted onto an item (schema.md §6): a simple
     * trial ends `trial_length` units out; a phased *free* trial ends when
     * phase 0's duration elapses. A paid phase-0 has no trial clock — its
     * boundary is the subscription's `current_period_end`.
     */
    private function itemTrialEnd(Price $price, Carbon $now): ?Carbon
    {
        if ($price->trial_length !== null && $price->trial_unit !== null) {
            return $this->addTrialUnit($now->copy(), $price->trial_unit, $price->trial_length);
        }

        $phaseZero = $price->phases->firstWhere('sequence', 0);

        if ($phaseZero !== null && ($phaseZero->chargePrice->unit_amount ?? 0) === 0) {
            return $this->addInterval($now->copy(), $phaseZero->duration_interval, $phaseZero->duration_count);
        }

        return null;
    }

    /**
     * Choose the initial billing clock and — unless the base item is on a free
     * trial — create the signup invoice (SUBSCRIPTIONS_DESIGN §4). Runs inside
     * the creation transaction; everything here is a local write. Returns the
     * invoice (or null) so the caller can charge it after commit.
     *
     * @param  list<array{item: SubscriptionItem, price: Price, quantity: int}>  $items
     */
    private function settleInitialState(
        Team $team,
        Subscription $subscription,
        Customer $customer,
        CollectionMode $collectionMode,
        array $items,
        Carbon $now,
        bool $freeTrial,
        ?Discount $discount,
        ?DiscountRedemption $redemption,
    ): ?Invoice {
        $basePrice = $items[0]['price'] ?? null;

        // The renewal clock: a free trial runs to its trial end; a paid phase-0
        // runs for the phase's duration; otherwise it's one billing interval.
        $subscription->current_period_end = $this->initialPeriodEnd($basePrice, $now, $subscription->trial_ends_at);
        $subscription->save();

        // A free trial charges nothing at day 0 — the plan item's trial anchors
        // the subscription and every add-on rides it (GAP-4). The first invoice
        // lands at conversion (AdvanceSubscriptionPhases), which also applies
        // the discount for the first time.
        if ($freeTrial) {
            return null;
        }

        $lines = $this->buildDayZeroLines($items);

        if ($lines === []) {
            return null;
        }

        $invoice = $this->createInvoice->handle(
            team: $team,
            customer: $customer,
            billingReason: InvoiceBillingReason::SubscriptionCreate,
            collectionMode: $collectionMode,
            lines: $lines,
            subscription: $subscription,
            dueAt: $collectionMode === CollectionMode::Manual ? $now->copy()->addDays(7) : null,
            discount: $discount,
        );

        // The day-0 invoice is the discount's first cycle — decrement it.
        if ($redemption !== null && $invoice->discount_total > 0) {
            $redemption->recordApplied();
        }

        return $invoice;
    }

    /**
     * Resolve the discount to redeem, by id or code, scoped to the team.
     *
     * @param  array<string, mixed>  $data
     */
    private function resolveDiscount(Team $team, array $data): ?Discount
    {
        if (isset($data['discount_id'])) {
            return $team->discounts()->find((int) $data['discount_id']);
        }

        $code = trim((string) ($data['discount_code'] ?? ''));

        if ($code !== '') {
            return $team->discounts()->where('code', $code)->first();
        }

        return null;
    }

    /**
     * Redeem a discount onto the freshly-built subscription (schema.md §7):
     * gate on eligibility + the global cap, attach `discount_id`, snapshot the
     * interval budget, and bump `times_redeemed`. Returns the redemption so the
     * caller can decrement it when it first applies.
     */
    private function redeemDiscount(
        Subscription $subscription,
        Customer $customer,
        ?Discount $discount,
        string $currency,
        Carbon $now,
    ): ?DiscountRedemption {
        if ($discount === null) {
            return null;
        }

        if (! $discount->isRedeemableBySubscriptionItems($subscription->items()->get(), $currency)) {
            throw new InvalidArgumentException(
                "Discount {$discount->public_id} isn't eligible for this subscription (or has reached its redemption limit)."
            );
        }

        $subscription->forceFill(['discount_id' => $discount->id])->save();

        $redemption = $subscription->discountRedemptions()->create([
            'discount_id' => $discount->id,
            'customer_id' => $customer->id,
            'remaining_intervals' => $discount->initialRemainingIntervals(),
            'applied_at' => $now,
        ]);

        $discount->increment('times_redeemed');

        return $redemption;
    }

    /**
     * The day-0 renewal clock end. Free trials and paid phase-0 ramps run for
     * their own duration; a plain subscription runs one billing interval.
     */
    private function initialPeriodEnd(?Price $basePrice, Carbon $now, ?CarbonInterface $trialEndsAt): ?Carbon
    {
        if ($trialEndsAt !== null) {
            return Carbon::instance($trialEndsAt);
        }

        if ($basePrice === null) {
            return null;
        }

        $phaseZero = $basePrice->phases->firstWhere('sequence', 0);

        if ($phaseZero !== null) {
            return $this->addInterval($now->copy(), $phaseZero->duration_interval, $phaseZero->duration_count);
        }

        return $this->addInterval($now->copy(), $basePrice->billing_interval ?? BillingInterval::Month, $basePrice->billing_frequency);
    }

    /**
     * Build the day-0 invoice lines: every item that isn't itself on a free
     * trial, billed at its effective (phase-0 or home) price. Reached only
     * when the base item is not free-trialing.
     *
     * @param  list<array{item: SubscriptionItem, price: Price, quantity: int}>  $items
     * @return list<array{subscriptionItem: SubscriptionItem, price: Price, product: Product, kind: InvoiceLineKind, description: string, unitAmount: int, quantity: int}>
     */
    private function buildDayZeroLines(array $items): array
    {
        $lines = [];

        foreach ($items as $billed) {
            // An add-on carrying its own free trial doesn't bill now — it keeps
            // its trial and is invoiced at its own conversion.
            if ($this->startsFreeTrial($billed['price'])) {
                continue;
            }

            $chargePrice = $billed['item']->effectiveChargePrice();
            $product = $chargePrice->product;

            $lines[] = [
                'subscriptionItem' => $billed['item'],
                'price' => $chargePrice,
                'product' => $product,
                'kind' => InvoiceLineKind::from($billed['item']->kind->value),
                'description' => $product->name.' · '.$chargePrice->toPickerLabel(),
                'unitAmount' => $chargePrice->unit_amount ?? 0,
                'quantity' => $billed['quantity'],
            ];
        }

        return $lines;
    }

    /**
     * Add N billing intervals to a date.
     */
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

    /**
     * Add N simple-trial units to a date.
     */
    private function addTrialUnit(CarbonInterface $date, TrialUnit $unit, int $count): Carbon
    {
        $mutable = Carbon::instance($date);

        return match ($unit) {
            TrialUnit::Day => $mutable->addDays($count),
            TrialUnit::Week => $mutable->addWeeks($count),
            TrialUnit::Month => $mutable->addMonths($count),
        };
    }
}
