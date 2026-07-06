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
use App\Enums\TrialDurationType;
use App\Enums\TrialEndBehavior;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Price;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\SubscriptionItem;
use App\Models\Team;
use App\Models\TrialOffer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Builds a subscription from a customer + a list of line items (each a plain
 * price or a trial offer) + a collection mode. This is the single seam the
 * dashboard controller and the future Phase-10 API both call so the two never
 * diverge (SUBSCRIPTIONS_DESIGN §7.4, §17.11).
 *
 * Money moves for real when there's something to bill (Phase 6): a billed
 * line becomes an Invoice, and automatic-with-card charges it via the real
 * Nomba tokenized-card call ({@see ChargeInvoice}). A free trial still stages
 * nothing — there's nothing to bill (SUBSCRIPTIONS_DESIGN §17.6).
 */
class CreateSubscription
{
    public function __construct(
        private readonly CreateInvoice $createInvoice,
        private readonly CollectInvoice $collectInvoice,
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

            $earliestTrialEnd = null;
            $earliestFreeTrialEnd = null;
            /** @var list<array{item: SubscriptionItem, price: Price, quantity: int}> $billedItems */
            $billedItems = [];

            foreach ($lines as $line) {
                $item = $subscription->items()->create([
                    'price_id' => $line['price']->id,
                    'product_id' => $line['price']->product_id,
                    'quantity' => $line['quantity'],
                    'status' => SubscriptionItemStatus::Active,
                ]);

                if ($line['offer'] !== null) {
                    $trialEnd = $this->applyTrial($item, $line['offer'], $customer, $now);
                    $earliestTrialEnd = $this->earliest($earliestTrialEnd, $trialEnd);

                    // Free trial (price = 0): no payment now, skips to trialing.
                    // Paid trial (price > 0): billed at signup and each cycle at
                    // the intro price until it converts — a normal billing line
                    // (schema.md §5, Stripe treats paid trials as active).
                    if (($line['price']->unit_amount ?? 0) === 0) {
                        $earliestFreeTrialEnd = $this->earliest($earliestFreeTrialEnd, $trialEnd);
                    } else {
                        $billedItems[] = ['item' => $item, 'price' => $line['price'], 'quantity' => $line['quantity']];
                    }
                } else {
                    $billedItems[] = ['item' => $item, 'price' => $line['price'], 'quantity' => $line['quantity']];
                }
            }

            $invoice = $this->settleInitialState($team, $subscription, $customer, $collectionMode, $earliestTrialEnd, $earliestFreeTrialEnd, $billedItems, $now);

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

        return $subscription;
    }

    /**
     * Turn raw item inputs into resolved lines, filtering to recurring prices —
     * a subscription bills recurring prices only. A trial line carries its
     * offer; a plain price line has `offer` set to null.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array{kind: string, price: Price, quantity: int, offer: TrialOffer|null}>
     */
    private function resolveLines(Team $team, array $items): array
    {
        if ($items === []) {
            throw new InvalidArgumentException('A subscription needs at least one line item.');
        }

        $lines = [];

        foreach ($items as $item) {
            $quantity = max(1, (int) ($item['quantity'] ?? 1));

            if (($item['kind'] ?? 'price') === 'trial') {
                /** @var TrialOffer $offer */
                $offer = $team->trialOffers()->with(['trialPrice', 'transitionPrice'])->findOrFail((int) $item['trial_offer_id']);

                $lines[] = [
                    'kind' => 'trial',
                    'price' => $offer->trialPrice,
                    'offer' => $offer,
                    'quantity' => $quantity,
                ];

                continue;
            }

            /** @var Price $price */
            $price = $team->prices()->findOrFail((int) $item['price_id']);

            if ($price->billing_interval === null) {
                throw new InvalidArgumentException('Only recurring prices can be subscribed to.');
            }

            $lines[] = ['kind' => 'price', 'price' => $price, 'quantity' => $quantity, 'offer' => null];
        }

        // A product can only appear once — a plain price and a trial for the
        // same product describe the same subscription (a trial already carries
        // its post-trial transition price), so stacking them double-charges.
        $productIds = array_map(fn (array $line): int => $line['price']->product_id, $lines);
        if (count($productIds) !== count(array_unique($productIds))) {
            throw new InvalidArgumentException('Each product can only appear once on a subscription — a trial already includes its regular price.');
        }

        return $lines;
    }

    /**
     * A subscription is single-currency for life — reject a mixed cart.
     *
     * @param  array<int, array{kind: string, price: Price, quantity: int, offer: TrialOffer|null}>  $lines
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
     * Snapshot a trial offer onto an item (schema.md §5) and return its end.
     * Enforces once-per-customer at application time.
     */
    private function applyTrial(SubscriptionItem $item, TrialOffer $offer, Customer $customer, Carbon $startsAt): Carbon
    {
        if ($offer->once_per_customer) {
            $used = $customer->subscriptionItemTrials()
                ->where('trial_offer_id', $offer->id)
                ->exists();

            if ($used) {
                throw new InvalidArgumentException("{$customer->displayName()} has already used the \"{$offer->name}\" trial.");
            }
        }

        // Relative duration only for MVP (timestamp deferred, schema.md §5).
        $iterations = max(1, $offer->duration_iterations ?? 1);
        $endsAt = $this->addInterval(
            $startsAt->copy(),
            $offer->trialPrice->billing_interval ?? BillingInterval::Month,
            $offer->trialPrice->billing_frequency * $iterations,
        );

        $item->currentTrial()->create([
            'team_id' => $offer->team_id,
            'trial_offer_id' => $offer->id,
            'customer_id' => $customer->id,
            'trial_price_id' => $offer->trial_price_id,
            'transition_price_id' => $offer->transition_price_id,
            'duration_type' => TrialDurationType::Relative,
            'duration_iterations' => $iterations,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'status' => SubscriptionItemTrialStatus::Active,
        ]);

        return $endsAt;
    }

    /**
     * Choose the initial billing clock and, when there's something to bill,
     * create the invoice for it (SUBSCRIPTIONS_DESIGN §4). Runs inside the
     * creation transaction — everything here is a local write, no external
     * calls. Returns the invoice so {@see collectInitialPayment()} can charge
     * it afterwards; null for a pure free trial, which has nothing to bill.
     *
     * @param  list<array{item: SubscriptionItem, price: Price, quantity: int}>  $billedItems
     */
    private function settleInitialState(
        Team $team,
        Subscription $subscription,
        Customer $customer,
        CollectionMode $collectionMode,
        ?Carbon $earliestTrialEnd,
        ?Carbon $earliestFreeTrialEnd,
        array $billedItems,
        Carbon $now,
    ): ?Invoice {
        // trial_ends_at is the conversion clock for any trial, free or paid.
        $subscription->trial_ends_at = $earliestTrialEnd;

        if ($earliestFreeTrialEnd !== null && $billedItems === []) {
            // Pure free trial: nothing bills today, so there's nothing to
            // invoice — start trialing, with the trial end as the next
            // billing moment (schema.md §5).
            $subscription->current_period_end = $earliestFreeTrialEnd;
            $subscription->status = SubscriptionStatus::Trialing;
            $subscription->save();

            return null;
        }

        // Otherwise at least one line bills now — a regular price or a paid
        // trial's intro price. The next charge is one interval away.
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
     * ({@see CreateInvoice}) — one line per item, at whatever price it's
     * currently billing (the regular price, or a paid trial's intro price).
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
            'kind' => InvoiceLineKind::Subscription,
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

    private function earliest(?Carbon $current, Carbon $candidate): Carbon
    {
        return $current === null || $candidate->lt($current) ? $candidate : $current;
    }
}
