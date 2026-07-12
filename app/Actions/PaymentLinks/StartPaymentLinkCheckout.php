<?php

namespace App\Actions\PaymentLinks;

use App\Actions\Invoicing\CreateInvoice;
use App\Actions\Invoicing\GenerateInvoiceCheckout;
use App\Enums\CatalogStatus;
use App\Enums\CollectionMode;
use App\Enums\InvoiceBillingReason;
use App\Enums\InvoiceLineKind;
use App\Enums\PriceType;
use App\Exceptions\Nomba\NombaConnectionException;
use App\Models\Customer;
use App\Models\PaymentLink;
use App\Services\Nomba\NombaModeResolver;
use InvalidArgumentException;

/**
 * V2 payment links point at a price only. Free-trial links (keyed off
 * `prices.trial_*` instead of the removed trial_offers object) come back
 * in V2-1 alongside the plan-aware catalog authoring.
 */
class StartPaymentLinkCheckout
{
    public function __construct(
        private readonly CreateInvoice $createInvoice,
        private readonly GenerateInvoiceCheckout $generateCheckout,
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
        $paymentLink->loadMissing(['team.processorConnection', 'product', 'price']);

        $this->assertCanCheckout($paymentLink);

        $team = $paymentLink->team;
        $customer = $this->resolveCustomer($paymentLink, $buyer);

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

        if ($paymentLink->price->status === CatalogStatus::Archived || ! $paymentLink->price->purchasable) {
            throw new InvalidArgumentException('This payment link is no longer available.');
        }

        if (($paymentLink->price->unit_amount ?? 0) <= 0) {
            throw new InvalidArgumentException('This payment link is not available for free or custom-priced catalog rows yet.');
        }

        // A recurring checkout becomes a subscription on settlement, and
        // subscription items require a plan (schema.md §6).
        if ($paymentLink->price->type === PriceType::Recurring && $paymentLink->price->plan_id === null) {
            throw new InvalidArgumentException('This payment link\'s price does not belong to a plan yet.');
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
