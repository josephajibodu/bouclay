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
use App\Enums\TrialEndBehavior;
use App\Exceptions\Nomba\NombaConnectionException;
use App\Models\Customer;
use App\Models\PaymentLink;
use App\Services\Nomba\NombaModeResolver;
use InvalidArgumentException;

class StartPaymentLinkCheckout
{
    public function __construct(
        private readonly CreateInvoice $createInvoice,
        private readonly GenerateInvoiceCheckout $generateCheckout,
        private readonly CreateSubscription $createSubscription,
        private readonly NombaModeResolver $modeResolver,
    ) {
        //
    }

    /**
     * @param  array{name?: string|null, email: string}  $buyer
     *
     * @throws InvalidArgumentException
     * @throws NombaConnectionException
     */
    public function handle(PaymentLink $paymentLink, array $buyer): string
    {
        $paymentLink->loadMissing(['team.processorConnection', 'product', 'price', 'trialOffer.trialPrice', 'trialOffer.transitionPrice']);

        $this->assertCanCheckout($paymentLink);

        $team = $paymentLink->team;
        $customer = $this->resolveCustomer($paymentLink, $buyer);

        if ($paymentLink->trial_offer_id !== null) {
            $this->createSubscription->handle($team, [
                'customer_id' => $customer->id,
                'collection_mode' => CollectionMode::Automatic->value,
                'trial_end_behavior' => TrialEndBehavior::CreateInvoice->value,
                'items' => [[
                    'kind' => 'trial',
                    'trial_offer_id' => $paymentLink->trial_offer_id,
                    'quantity' => 1,
                ]],
            ]);

            return route('hosted.payment-links.show', [
                'publicId' => $paymentLink->public_id,
                'trial_started' => 1,
                'email' => $customer->email,
            ]);
        }

        $connection = $team->processorConnection;
        $mode = $this->modeResolver->forConnection($connection);

        if ($connection === null || $mode === null) {
            throw new InvalidArgumentException('This business has not connected payments yet.');
        }

        if ($paymentLink->price->type === PriceType::Recurring) {
            return $this->startRecurringCheckout($paymentLink, $customer);
        }

        return $this->startOneTimeCheckout($paymentLink, $customer);
    }

    private function assertCanCheckout(PaymentLink $paymentLink): void
    {
        if (! $paymentLink->active) {
            throw new InvalidArgumentException('This payment link is no longer active.');
        }

        if ($paymentLink->product->status === CatalogStatus::Archived) {
            throw new InvalidArgumentException('This payment link is no longer available.');
        }

        if ($paymentLink->trial_offer_id !== null) {
            $trialOffer = $paymentLink->trialOffer;

            if ($trialOffer === null || ! $trialOffer->active || $trialOffer->trialPrice->status === CatalogStatus::Archived || $trialOffer->transitionPrice->status === CatalogStatus::Archived) {
                throw new InvalidArgumentException('This trial offer is no longer available.');
            }

            if ($trialOffer->trialPrice->type !== PriceType::Recurring || ($trialOffer->trialPrice->unit_amount ?? 0) !== 0) {
                throw new InvalidArgumentException('Hosted trial links are available for free recurring trial offers.');
            }

            if ($trialOffer->transitionPrice->type !== PriceType::Recurring || ($trialOffer->transitionPrice->unit_amount ?? 0) <= 0) {
                throw new InvalidArgumentException('Hosted trial links need a paid recurring transition price.');
            }

            if ($trialOffer->trialPrice->currency !== $trialOffer->transitionPrice->currency) {
                throw new InvalidArgumentException('The trial and transition prices must use the same currency.');
            }

            return;
        }

        if ($paymentLink->price === null || $paymentLink->price->status === CatalogStatus::Archived) {
            throw new InvalidArgumentException('This payment link is no longer available.');
        }

        if (($paymentLink->price->unit_amount ?? 0) <= 0) {
            throw new InvalidArgumentException('This payment link is not available for free or custom-priced catalog rows yet.');
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
                'currency' => $this->customerCurrency($paymentLink),
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

    private function customerCurrency(PaymentLink $paymentLink): string
    {
        if ($paymentLink->trialOffer !== null) {
            return $paymentLink->trialOffer->trialPrice->currency;
        }

        return $paymentLink->price->currency;
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
                'kind' => InvoiceLineKind::Subscription,
                'description' => $paymentLink->product->name.' · '.$paymentLink->price->toPickerLabel(),
                'unitAmount' => $paymentLink->price->unit_amount ?? 0,
                'quantity' => 1,
            ]],
        );

        $checkout = $this->generateCheckout->handle(
            team: $paymentLink->team,
            invoice: $invoice,
            tokenizeCard: true,
            allowedPaymentMethods: ['Card'],
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
     * @throws NombaConnectionException
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
            allowedPaymentMethods: ['Card'],
            setDefaultPaymentMethod: true,
        );

        return $checkout['checkoutLink'];
    }
}
