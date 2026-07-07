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
use App\Exceptions\Nomba\NombaConnectionException;
use App\Models\Customer;
use App\Models\Invoice;
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
        $paymentLink->loadMissing(['team.processorConnection', 'product', 'price']);

        $this->assertCanCheckout($paymentLink);

        $team = $paymentLink->team;
        $connection = $team->processorConnection;
        $mode = $this->modeResolver->forConnection($connection);

        if ($connection === null || $mode === null) {
            throw new InvalidArgumentException('This business has not connected payments yet.');
        }

        $customer = $this->resolveCustomer($paymentLink, $buyer);

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

        if ($paymentLink->product->status === CatalogStatus::Archived || $paymentLink->price->status === CatalogStatus::Archived) {
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

    /**
     * @throws InvalidArgumentException
     */
    private function startRecurringCheckout(PaymentLink $paymentLink, Customer $customer): string
    {
        $subscription = $this->createSubscription->handle($paymentLink->team, [
            'customer_id' => $customer->id,
            'collection_mode' => CollectionMode::Automatic->value,
            'items' => [
                ['kind' => 'price', 'price_id' => $paymentLink->price_id, 'quantity' => 1],
            ],
        ]);

        $invoice = $subscription->invoices()
            ->where('billing_reason', InvoiceBillingReason::SubscriptionCreate)
            ->latest('id')
            ->first();

        if (! $invoice instanceof Invoice) {
            throw new InvalidArgumentException('This subscription did not create an initial invoice.');
        }

        $checkoutLink = $invoice->fresh()->custom_data['checkout_link'] ?? null;

        if (! is_string($checkoutLink) || $checkoutLink === '') {
            throw new InvalidArgumentException($invoice->custom_data['collection_error'] ?? 'We could not start checkout for this subscription.');
        }

        return $checkoutLink;
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
