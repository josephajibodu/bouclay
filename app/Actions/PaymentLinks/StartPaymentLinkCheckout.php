<?php

namespace App\Actions\PaymentLinks;

use App\Actions\Invoicing\CreateInvoice;
use App\Actions\Invoicing\GenerateInvoiceCheckout;
use App\Actions\Subscriptions\CreateSubscription;
use App\Enums\CatalogStatus;
use App\Enums\CollectionMode;
use App\Enums\InvoiceBillingReason;
use App\Enums\InvoiceLineKind;
use App\Enums\PriceType;
use App\Models\Customer;
use App\Models\PaymentLink;
use App\Models\Price;
use App\Services\Gateways\GatewayException;
use App\Services\Gateways\GatewayModeResolver;
use InvalidArgumentException;

/**
 * V2 payment links point at a price only. A link whose price starts a free
 * trial (`prices.trial_*`, or a phased price with a ₦0 phase 0 — the shared
 * rule lives on {@see Price::startsFreeTrial()}) collects nothing today: it
 * starts the subscription in `trialing` through the same {@see CreateSubscription}
 * seam the dashboard and API use, and never reaches a gateway.
 *
 * Trials needing a card up front (`trial_requires_payment_info`) are refused
 * rather than charged — see {@see assertTrialIsSupported()}.
 */
class StartPaymentLinkCheckout
{
    public function __construct(
        private readonly CreateInvoice $createInvoice,
        private readonly GenerateInvoiceCheckout $generateCheckout,
        private readonly GatewayModeResolver $modeResolver,
        private readonly CreateSubscription $createSubscription,
    ) {
        //
    }

    /**
     * @param  array{name?: string|null, email: string}  $buyer
     *
     * @throws InvalidArgumentException
     * @throws GatewayException
     */
    public function handle(PaymentLink $paymentLink, array $buyer): PaymentLinkCheckoutResult
    {
        $paymentLink->loadMissing([
            'team.processorConnection',
            'product',
            'price',
        ]);

        $this->assertCanCheckout($paymentLink);

        $team = $paymentLink->team;

        $connection = $team->processorConnection;
        $mode = $this->modeResolver->forConnection($connection);

        if ($connection === null || $mode === null) {
            throw new InvalidArgumentException('This business has not connected payments yet.');
        }

        $customer = $this->resolveCustomer($paymentLink, $buyer);

        // A free trial bills nothing at day 0, so there is no order to take to
        // a gateway — resolve it before any checkout is built.
        if ($paymentLink->price->type === PriceType::Recurring
            && $paymentLink->price->startsFreeTrial()) {
            return $this->startTrialSubscription($paymentLink, $customer);
        }

        if ($paymentLink->price->type === PriceType::Recurring) {
            return PaymentLinkCheckoutResult::redirect(
                $this->startRecurringCheckout($paymentLink, $customer),
            );
        }

        return PaymentLinkCheckoutResult::redirect(
            $this->startOneTimeCheckout($paymentLink, $customer),
        );
    }

    /**
     * Start a free trial with no payment leg, through the one shared creation
     * seam so the link path gets trial anchoring, `price_trial_redemptions`,
     * `trial_once_per_customer` and phase counters identically to every other
     * surface (SUBSCRIPTIONS_DESIGN §7.4).
     */
    private function startTrialSubscription(PaymentLink $paymentLink, Customer $customer): PaymentLinkCheckoutResult
    {
        $this->assertTrialIsSupported($paymentLink);

        $subscription = $this->createSubscription->handle($paymentLink->team, [
            'customer_id' => $customer->id,
            'collection_mode' => CollectionMode::Automatic->value,
            'items' => [[
                'price_id' => $paymentLink->price_id,
                'quantity' => 1,
            ]],
            'custom_data' => [
                'source' => 'payment_link',
                'payment_link_id' => $paymentLink->public_id,
            ],
        ]);

        return PaymentLinkCheckoutResult::trialStarted($subscription);
    }

    /**
     * `trial_requires_payment_info` means the card is stored but **not charged**
     * (BILLING_SIMULATIONS SIM-01). No connected gateway can mint a token
     * without a real charge — Nomba tokenises only as a side effect of a
     * successful payment — so this link has no correct behaviour available.
     * Refusing is the only honest option: the alternative is what this code
     * used to do, which was silently charge the full price on day 0.
     */
    private function assertTrialIsSupported(PaymentLink $paymentLink): void
    {
        if ($paymentLink->price->trial_requires_payment_info) {
            throw new InvalidArgumentException(
                'This trial needs card details up front, which this business’s payment provider cannot collect without charging. Please contact the business to get started.'
            );
        }
    }

    private function assertCanCheckout(PaymentLink $paymentLink): void
    {
        if (! $paymentLink->active) {
            throw new InvalidArgumentException('This payment link is no longer active.');
        }

        if ($paymentLink->product->status === CatalogStatus::Archived) {
            throw new InvalidArgumentException('This payment link is no longer available.');
        }

        if ($paymentLink->price->status === CatalogStatus::Archived || ! $paymentLink->price->purchasable) {
            throw new InvalidArgumentException('This payment link is no longer available.');
        }

        if (($paymentLink->price->unit_amount ?? 0) <= 0) {
            throw new InvalidArgumentException('This payment link is not available for free or custom-priced catalog rows yet.');
        }

        // A recurring checkout becomes a subscription on settlement, so its
        // price must pass the one shared purchasability rule — plan-bearing,
        // plan itself active, not phase-only
        // (Price::purchasableForNewSubscriptions).
        if ($paymentLink->price->type === PriceType::Recurring
            && ! $paymentLink->price->isPurchasableForNewSubscriptions()) {
            throw new InvalidArgumentException('This payment link is no longer available.');
        }

        // Deferred to v2 (schema.md §3): a payment link can't express "this
        // price is step 0 of Journey J" — block checkout rather than
        // silently sell a flat price that never advances, even if the link
        // predates the journey being authored.
        if ($paymentLink->price->type === PriceType::Recurring
            && $paymentLink->price->startsPricingJourney()) {
            throw new InvalidArgumentException('This payment link is no longer available.');
        }
    }

    /**
     * @param  array{name?: string|null, email: string}  $buyer
     */
    private function resolveCustomer(PaymentLink $paymentLink, array $buyer): Customer
    {
        $email = mb_strtolower(trim($buyer['email']));
        $name = trim((string) ($buyer['name'] ?? '')) ?: null;

        /** @var Customer $customer */
        $customer = $paymentLink->team->customers()
            ->where('email', $email)
            ->first()
            ?? $paymentLink->team->customers()->create([
                'name' => $name,
                'email' => $email,
                'currency' => $paymentLink->price->currency,
                'custom_data' => [
                    'source' => 'payment_link',
                    'payment_link_id' => $paymentLink->public_id,
                ],
            ]);

        if ($customer->name === null && $name !== null) {
            $customer->forceFill(['name' => $name])->save();
        }

        return $customer;
    }

    private function startRecurringCheckout(PaymentLink $paymentLink, Customer $customer): string
    {
        $invoice = $this->createInvoice->handle(
            team: $paymentLink->team,
            customer: $customer,
            billingReason: InvoiceBillingReason::SubscriptionCreate,
            collectionMode: CollectionMode::Automatic,
            lines: [[
                'price' => $paymentLink->price,
                'product' => $paymentLink->product,
                'kind' => InvoiceLineKind::Plan,
                'description' => $paymentLink->product->name.' · '.$paymentLink->price->toPickerLabel(),
                'unitAmount' => $paymentLink->price->unit_amount ?? 0,
                'quantity' => 1,
            ]],
        );

        $checkout = $this->generateCheckout->handle(
            team: $paymentLink->team,
            invoice: $invoice,
            tokenizeCard: true,
            cardOnly: true,
            setDefaultPaymentMethod: true,
            mode: $this->modeResolver->forConnection($paymentLink->team->processorConnection),
        );

        $invoice->refresh();
        $invoice->forceFill([
            'custom_data' => [
                ...($invoice->custom_data ?? []),
                'pending_subscription' => [
                    'source' => 'payment_link',
                    'payment_link_id' => $paymentLink->public_id,
                    'price_id' => $paymentLink->price_id,
                    'quantity' => 1,
                ],
            ],
        ])->save();

        return $checkout['checkoutLink'];
    }

    /**
     * @throws GatewayException
     */
    private function startOneTimeCheckout(PaymentLink $paymentLink, Customer $customer): string
    {
        $invoice = $this->createInvoice->handle(
            team: $paymentLink->team,
            customer: $customer,
            billingReason: InvoiceBillingReason::Manual,
            collectionMode: CollectionMode::Automatic,
            lines: [[
                'price' => $paymentLink->price,
                'product' => $paymentLink->product,
                'kind' => InvoiceLineKind::OneTime,
                'description' => $paymentLink->product->name.' · '.$paymentLink->price->toPickerLabel(),
                'unitAmount' => $paymentLink->price->unit_amount ?? 0,
                'quantity' => 1,
            ]],
        );

        $checkout = $this->generateCheckout->handle(
            team: $paymentLink->team,
            invoice: $invoice,
            tokenizeCard: true,
            cardOnly: true,
            setDefaultPaymentMethod: true,
        );

        return $checkout['checkoutLink'];
    }
}
