<?php

namespace App\Actions\Subscriptions;

use App\Actions\Invoicing\CollectInvoice;
use App\Actions\Invoicing\CreateInvoice;
use App\Actions\Webhooks\EmitOutboundEvent;
use App\Enums\BillingInterval;
use App\Enums\CatalogStatus;
use App\Enums\CollectionMode;
use App\Enums\InvoiceBillingReason;
use App\Enums\InvoiceLineKind;
use App\Enums\OutboundEventType;
use App\Enums\PlanStatus;
use App\Enums\ScheduleEndBehavior;
use App\Enums\SubscriptionItemKind;
use App\Enums\SubscriptionItemStatus;
use App\Enums\SubscriptionScheduleStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\TrialEndBehavior;
use App\Enums\TrialUnit;
use App\Models\Customer;
use App\Models\Discount;
use App\Models\DiscountRedemption;
use App\Models\Invoice;
use App\Models\Price;
use App\Models\PricingJourney;
use App\Models\PricingJourneyStep;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\SubscriptionItem;
use App\Models\SubscriptionSchedule;
use App\Models\Team;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Builds a subscription from a customer + a list of line items + a
 * collection mode. This is the single seam the dashboard controller and the
 * API both call so the two never diverge (SUBSCRIPTIONS_DESIGN §7.4, §17.11).
 *
 * Each line is one of: `{price_id}` (flat, unchanged), `{price_phases_id}`
 * (a Pricing Journey — its steps are copied into a new
 * {@see SubscriptionSchedule}), or `{schedule_steps: [...]}` (an ad hoc,
 * journey-less schedule, same copy mechanism). Only the base plan line
 * (index 0) may carry a journey or ad hoc schedule; add-ons stay flat
 * (schema.md §5).
 *
 * V2 catalog rules (schema.md §3/§6): only plan-bearing recurring prices are
 * subscribable — `subscription_items.plan_id` is NOT NULL, so a plan-less
 * one-time price is rejected here. The first item is the base `plan` item;
 * further items are `addon`s.
 *
 * Trial anchoring (schema.md §5, GAP-4, Stripe-style): the subscription's
 * trial anchors to the base plan item. A base item on a **free** trial
 * (`prices.trial_length`, or a schedule whose step-0 charge is ₦0) starts
 * the subscription in `trialing` and bills **nothing** at day 0 — a no-trial
 * add-on rides the trial and is first invoiced at conversion. A base item
 * with a **paid** step-0 (`unit_amount > 0`) follows the normal
 * `incomplete → active` path, charging the step-0 price now. `trial_ends_at`
 * is snapshotted onto each item and mirrored to the subscription (earliest
 * active). Conversion / schedule progression lives in
 * {@see AdvanceSubscriptionSchedule}.
 *
 * @phpstan-type ScheduleStep array{price: Price, quantity: int, duration_interval: BillingInterval|null, duration_count: int|null}
 * @phpstan-type ResolvedLine array{price: Price, quantity: int, scheduleSteps: list<ScheduleStep>|null, journeyId: int|null, endBehavior: ScheduleEndBehavior|null}
 * @phpstan-type ResolvedItem array{item: SubscriptionItem, price: Price, quantity: int, scheduleSteps: list<ScheduleStep>|null}
 */
class CreateSubscription
{
    public function __construct(
        private readonly CreateInvoice $createInvoice,
        private readonly CollectInvoice $collectInvoice,
        private readonly EmitOutboundEvent $emitOutboundEvent,
        private readonly RedeemDiscount $redeemDiscount,
    ) {
        //
    }

    /**
     * @param  array<string, mixed>  $data  the validated request body (customer_id,
     *                                      collection_mode, items[], payment_method_id?,
     *                                      trial_end_behavior?, custom_data?)
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
        $freeTrial = $this->lineStartsFreeTrial($lines[0]);

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
                'custom_data' => $data['custom_data'] ?? null,
            ]);

            /** @var list<ResolvedItem> $items */
            $items = [];
            $earliestTrialEnd = null;
            /** @var list<array{schedule: SubscriptionSchedule, item: SubscriptionItem}> $pendingFinalizations */
            $pendingFinalizations = [];

            foreach ($lines as $index => $line) {
                $price = $line['price'];
                $trialEndsAt = $this->itemTrialEnd($line, $now);

                $item = $subscription->items()->create([
                    'price_id' => $price->id,
                    'plan_id' => $price->plan_id,
                    'product_id' => $price->product_id,
                    'kind' => $index === 0 ? SubscriptionItemKind::Plan : SubscriptionItemKind::Addon,
                    'quantity' => $line['quantity'],
                    'status' => SubscriptionItemStatus::Active,
                    'trial_ends_at' => $trialEndsAt,
                ]);

                if ($line['scheduleSteps'] !== null) {
                    $schedule = $this->createSchedule($item, $subscription, $line, $now);

                    if (count($line['scheduleSteps']) === 1) {
                        $pendingFinalizations[] = ['schedule' => $schedule, 'item' => $item];
                    }
                }

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

                $items[] = ['item' => $item, 'price' => $price, 'quantity' => $line['quantity'], 'scheduleSteps' => $line['scheduleSteps']];
            }

            $subscription->trial_ends_at = $earliestTrialEnd;

            // Redeem the discount now (schema.md §7): gate on eligibility,
            // snapshot the interval budget, attach it to the subscription. The
            // day-0 invoice (if any) applies + decrements it below; a free
            // trial's first application is deferred to conversion.
            $redemption = $discount !== null
                ? $this->redeemDiscount->handle($subscription, $discount, $now)
                : null;

            $invoice = $this->settleInitialState($team, $subscription, $customer, $collectionMode, $items, $now, $freeTrial, $discount, $redemption);

            // A schedule that started already on its terminal step (a 1-step
            // schedule) finalizes immediately — same collapse-to-flat/cancel
            // logic the advance-schedule worker applies at any later
            // boundary (schema.md §5). Runs after day-0 billing so a
            // `release` schedule's day-0 invoice (already at the terminal
            // price) settles normally first.
            foreach ($pendingFinalizations as $pending) {
                $this->finalizeSchedule($pending['schedule'], $pending['item'], $subscription, $now);
            }

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
     * @return list<ResolvedLine>
     */
    private function resolveLines(Team $team, array $items): array
    {
        if ($items === []) {
            throw new InvalidArgumentException('A subscription needs at least one line item.');
        }

        $lines = [];

        foreach ($items as $index => $item) {
            $lines[] = $this->resolveLine($team, $item, $index);
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
     * @param  array<string, mixed>  $item
     * @return ResolvedLine
     */
    private function resolveLine(Team $team, array $item, int $index): array
    {
        $quantity = max(1, (int) ($item['quantity'] ?? 1));

        $hasJourney = isset($item['price_phases_id']);
        $hasAdHoc = isset($item['schedule_steps']);
        $hasFlat = isset($item['price_id']);

        if ((int) $hasJourney + (int) $hasAdHoc + (int) $hasFlat !== 1) {
            throw new InvalidArgumentException("Item {$index} must specify exactly one of price_id, price_phases_id, or schedule_steps.");
        }

        if (($hasJourney || $hasAdHoc) && $index !== 0) {
            throw new InvalidArgumentException('A Pricing Journey or ad hoc schedule can only be used for the base plan line — add-ons stay flat.');
        }

        $journeyId = null;

        if ($hasJourney) {
            $journeyId = (int) $item['price_phases_id'];
            $scheduleSteps = $this->resolveJourneySchedule($team, $journeyId);
        } elseif ($hasAdHoc) {
            $scheduleSteps = $this->resolveAdHocSchedule($team, $item['schedule_steps']);
        } else {
            $scheduleSteps = null;
        }

        if ($scheduleSteps !== null) {
            $price = $scheduleSteps[0]['price'];
            $this->assertUsableStepZeroPrice($price);
        } else {
            /** @var Price $price */
            $price = $team->prices()->with('product')->findOrFail((int) $item['price_id']);
            $this->assertPurchasablePlanPrice($price);
        }

        $endBehavior = $scheduleSteps !== null
            ? (ScheduleEndBehavior::tryFrom((string) ($item['end_behavior'] ?? '')) ?? ScheduleEndBehavior::Release)
            : null;

        return [
            'price' => $price,
            'quantity' => $quantity,
            'scheduleSteps' => $scheduleSteps,
            'journeyId' => $journeyId,
            'endBehavior' => $endBehavior,
        ];
    }

    /**
     * Copy an active Pricing Journey's steps (schema.md §3) — terminal
     * shape, currency, and cadence consistency are already enforced at
     * authoring time by {@see \App\Actions\Catalog\SyncPricingJourney}, so
     * this just resolves the rows.
     *
     * @return list<ScheduleStep>
     */
    private function resolveJourneySchedule(Team $team, int $journeyId): array
    {
        /** @var PricingJourney $journey */
        $journey = PricingJourney::query()
            ->where('team_id', $team->id)
            ->where('status', CatalogStatus::Active)
            ->with('steps.price.product')
            ->findOrFail($journeyId);

        if ($journey->steps->isEmpty()) {
            throw new InvalidArgumentException('This pricing journey has no steps configured.');
        }

        return array_values($journey->steps
            ->map(fn (PricingJourneyStep $step): array => [
                'price' => $step->price,
                'quantity' => $step->quantity,
                'duration_interval' => $step->duration_interval,
                'duration_count' => $step->duration_count,
            ])
            ->all());
    }

    /**
     * Resolve and validate an ad hoc, journey-less schedule (schema.md §5)
     * — Product-scoped and cadence-consistent, the same rule a Pricing
     * Journey enforces at authoring time, just checked here instead since
     * there's no template row to have validated it already.
     *
     * @param  array<int, array<string, mixed>>  $steps
     * @return list<array{price: Price, quantity: int, duration_interval: ?BillingInterval, duration_count: ?int}>
     */
    private function resolveAdHocSchedule(Team $team, array $steps): array
    {
        $steps = array_values($steps);

        if ($steps === []) {
            throw new InvalidArgumentException('An ad hoc schedule needs at least one step.');
        }

        $resolved = [];
        $firstProductId = null;
        $firstCurrency = null;
        $firstInterval = null;
        $firstFrequency = null;

        foreach ($steps as $sequence => $step) {
            /** @var Price $price */
            $price = $team->prices()->with('product')->findOrFail((int) $step['price_id']);

            if ($price->billing_interval === null) {
                throw new InvalidArgumentException("Schedule step {$sequence} must charge a recurring price.");
            }

            if ($sequence === 0) {
                $firstProductId = $price->product_id;
                $firstCurrency = $price->currency;
                $firstInterval = $price->billing_interval;
                $firstFrequency = $price->billing_frequency;
            } else {
                if ($price->product_id !== $firstProductId) {
                    throw new InvalidArgumentException(
                        "Schedule step {$sequence} belongs to a different product than step 0 — an ad hoc schedule is Product-scoped, same as a Pricing Journey."
                    );
                }

                if ($price->currency !== $firstCurrency) {
                    throw new InvalidArgumentException(
                        "Schedule step {$sequence} charges a {$price->currency} price; step 0 is {$firstCurrency} — one currency per schedule."
                    );
                }

                if ($price->billing_interval !== $firstInterval || $price->billing_frequency !== $firstFrequency) {
                    throw new InvalidArgumentException(
                        "Schedule step {$sequence} has a different billing cadence than step 0 — a subscription has one renewal clock."
                    );
                }
            }

            $resolved[] = [
                'price' => $price,
                'quantity' => max(1, (int) ($step['quantity'] ?? 1)),
                'duration_interval' => isset($step['duration_interval']) ? BillingInterval::from($step['duration_interval']) : null,
                'duration_count' => isset($step['duration_count']) ? (int) $step['duration_count'] : null,
            ];
        }

        $this->assertScheduleTerminalShape($resolved);

        return $resolved;
    }

    /**
     * Every step but the last needs a duration; the last must be terminal
     * ("forever") — what the advance-schedule worker relies on to find the
     * steady state without special-casing the final index.
     *
     * @param  list<array{duration_interval: ?BillingInterval, duration_count: ?int}>  $steps
     */
    private function assertScheduleTerminalShape(array $steps): void
    {
        $lastIndex = array_key_last($steps);

        foreach ($steps as $sequence => $step) {
            $hasDuration = $step['duration_interval'] !== null && $step['duration_count'] !== null;

            if ($sequence === $lastIndex && $hasDuration) {
                throw new InvalidArgumentException('The last schedule step must be the terminal step — leave its duration empty.');
            }

            if ($sequence !== $lastIndex && ! $hasDuration) {
                throw new InvalidArgumentException("Schedule step {$sequence} needs a duration — only the last step can run forever.");
            }
        }
    }

    private function assertRecurringPlanPrice(Price $price): void
    {
        if ($price->billing_interval === null) {
            throw new InvalidArgumentException('Only recurring prices can be subscribed to.');
        }

        if ($price->plan_id === null) {
            throw new InvalidArgumentException(
                "Price {$price->public_id} does not belong to a plan — only plan-bearing prices can be subscribed to."
            );
        }
    }

    /**
     * The full flat-price check (schema.md §3, V2-1): archived prices, and
     * prices under a draft/archived plan, are not offered to new
     * subscriptions. Requires `purchasable = true` — the general "shows up
     * in a picker" gate.
     */
    private function assertPurchasablePlanPrice(Price $price): void
    {
        $this->assertRecurringPlanPrice($price);

        if (! $price->isPurchasableForNewSubscriptions()) {
            throw new InvalidArgumentException(
                "Price {$price->public_id} is not currently purchasable — its plan may be draft or archived."
            );
        }
    }

    /**
     * A schedule's step-0 price must still be active and under an active
     * plan at use time — but need NOT be `purchasable = true`: a step price
     * is deliberately selected through the journey/ad hoc mechanism, not
     * the general picker, and merchants routinely keep an intro-only price
     * hidden from that picker (schema.md §3).
     */
    private function assertUsableStepZeroPrice(Price $price): void
    {
        $this->assertRecurringPlanPrice($price);

        if ($price->status !== CatalogStatus::Active) {
            throw new InvalidArgumentException("Price {$price->public_id} is archived and can't start a new subscription.");
        }

        $price->loadMissing('plan');

        if ($price->plan?->status !== PlanStatus::Active) {
            throw new InvalidArgumentException("Price {$price->public_id}'s plan is not active.");
        }
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
     * Whether a line starts the subscription on a **free** trial: a simple
     * trial is always free during its window; a schedule whose step-0
     * charge is ₦0 is a free trial too, UNLESS step 0 is itself the
     * terminal ("forever") step — a permanently-free price has no trial
     * clock to run out, so it isn't a trial, just a ₦0 flat item.
     *
     * @param  ResolvedLine  $line
     */
    private function lineStartsFreeTrial(array $line): bool
    {
        if ($line['price']->trial_length !== null) {
            return true;
        }

        $scheduleSteps = $line['scheduleSteps'];

        if ($scheduleSteps === null || count($scheduleSteps) < 2) {
            return false;
        }

        return ($scheduleSteps[0]['price']->unit_amount ?? 0) === 0;
    }

    /**
     * The trial-window end snapshotted onto an item (schema.md §6): a simple
     * trial ends `trial_length` units out; a *free* step-0 ends when its
     * duration elapses. A paid step-0 has no trial clock — its boundary is
     * the subscription's `current_period_end`.
     *
     * @param  ResolvedLine  $line
     */
    private function itemTrialEnd(array $line, Carbon $now): ?Carbon
    {
        $price = $line['price'];

        if ($price->trial_length !== null && $price->trial_unit !== null) {
            return $this->addTrialUnit($now->copy(), $price->trial_unit, $price->trial_length);
        }

        $scheduleSteps = $line['scheduleSteps'];

        if ($scheduleSteps === null || count($scheduleSteps) < 2) {
            return null;
        }

        $stepZero = $scheduleSteps[0];

        if (($stepZero['price']->unit_amount ?? 0) === 0 && $stepZero['duration_interval'] !== null) {
            return $this->addInterval($now->copy(), $stepZero['duration_interval'], $stepZero['duration_count']);
        }

        return null;
    }

    /**
     * Choose the initial billing clock and — unless the base item is on a free
     * trial — create the signup invoice (SUBSCRIPTIONS_DESIGN §4). Runs inside
     * the creation transaction; everything here is a local write. Returns the
     * invoice (or null) so the caller can charge it after commit.
     *
     * @param  list<ResolvedItem>  $items
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
        $base = $items[0] ?? null;

        // The renewal clock: a free trial runs to its trial end; a paid
        // step-0 runs for the step's duration; otherwise it's one billing
        // interval.
        $subscription->current_period_end = $this->initialPeriodEnd($base, $now, $subscription->trial_ends_at);
        $subscription->save();

        // A free trial charges nothing at day 0 — the plan item's trial anchors
        // the subscription and every add-on rides it (GAP-4). The first invoice
        // lands at conversion (AdvanceSubscriptionSchedule), which also applies
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
     * The day-0 renewal clock end. Free trials and paid step-0 ramps run for
     * their own duration; a plain subscription runs one billing interval.
     *
     * @param  ResolvedItem|null  $base
     */
    private function initialPeriodEnd(?array $base, Carbon $now, ?CarbonInterface $trialEndsAt): ?Carbon
    {
        if ($trialEndsAt !== null) {
            return Carbon::instance($trialEndsAt);
        }

        if ($base === null) {
            return null;
        }

        $scheduleSteps = $base['scheduleSteps'];

        if ($scheduleSteps !== null && count($scheduleSteps) >= 2) {
            $stepZero = $scheduleSteps[0];

            if ($stepZero['duration_interval'] !== null) {
                return $this->addInterval($now->copy(), $stepZero['duration_interval'], $stepZero['duration_count']);
            }
        }

        $basePrice = $base['price'];

        return $this->addInterval($now->copy(), $basePrice->billing_interval ?? BillingInterval::Month, $basePrice->billing_frequency);
    }

    /**
     * Build the day-0 invoice lines: every item that isn't itself on a free
     * trial, billed at its current price. Reached only when the base item is
     * not free-trialing.
     *
     * @param  list<ResolvedItem>  $items
     * @return list<array{subscriptionItem: SubscriptionItem, price: Price, product: Product, kind: InvoiceLineKind, description: string, unitAmount: int, quantity: int}>
     */
    private function buildDayZeroLines(array $items): array
    {
        $lines = [];

        foreach ($items as $billed) {
            // An add-on carrying its own free trial doesn't bill now — it keeps
            // its trial and is invoiced at its own conversion.
            if ($billed['price']->startsFreeTrial()) {
                continue;
            }

            $price = $billed['price'];
            $product = $price->product;

            $lines[] = [
                'subscriptionItem' => $billed['item'],
                'price' => $price,
                'product' => $product,
                'kind' => InvoiceLineKind::from($billed['item']->kind->value),
                'description' => $product->name.' · '.$price->toPickerLabel(),
                'unitAmount' => $price->unit_amount ?? 0,
                'quantity' => $billed['quantity'],
            ];
        }

        return $lines;
    }

    /**
     * Copy a resolved journey/ad hoc step list into a customer-owned
     * {@see SubscriptionSchedule} (schema.md §5) — durations resolved to
     * absolute `starts_at`/`ends_at` dates anchored to `$now`, so the
     * advance-schedule worker never re-derives boundaries from interval
     * arithmetic.
     *
     * @param  ResolvedLine  $line
     */
    private function createSchedule(SubscriptionItem $item, Subscription $subscription, array $line, Carbon $now): SubscriptionSchedule
    {
        $schedule = SubscriptionSchedule::query()->create([
            'subscription_id' => $subscription->id,
            'subscription_item_id' => $item->id,
            'price_phases_id' => $line['journeyId'],
            'end_behavior' => $line['endBehavior'] ?? ScheduleEndBehavior::Release,
            'status' => SubscriptionScheduleStatus::Active,
        ]);

        $cursor = $now->copy();
        $firstStepId = null;

        foreach ($line['scheduleSteps'] as $sequence => $step) {
            $startsAt = $cursor->copy();
            $endsAt = $step['duration_interval'] !== null && $step['duration_count'] !== null
                ? $this->addInterval($startsAt->copy(), $step['duration_interval'], $step['duration_count'])
                : null;

            $scheduleStep = $schedule->steps()->create([
                'sequence' => $sequence,
                'price_id' => $step['price']->id,
                'quantity' => $step['quantity'],
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
            ]);

            if ($sequence === 0) {
                $firstStepId = $scheduleStep->id;
            }

            if ($endsAt !== null) {
                $cursor = $endsAt->copy();
            }
        }

        $item->forceFill(['current_schedule_step_id' => $firstStepId])->save();

        return $schedule;
    }

    /**
     * Finalize a schedule that started already on its terminal step (a
     * 1-step schedule) — the same collapse-to-flat/cancel logic
     * {@see AdvanceSubscriptionSchedule} applies at any later boundary
     * (schema.md §5), just triggered immediately since there's no future
     * boundary to wait for.
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
