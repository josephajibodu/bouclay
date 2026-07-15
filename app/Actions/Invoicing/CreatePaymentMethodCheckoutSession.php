<?php

namespace App\Actions\Invoicing;

use App\Enums\ApiKeyMode;
use App\Enums\CollectionMode;
use App\Enums\InvoiceBillingReason;
use App\Enums\InvoiceLineKind;
use App\Models\Customer;
use App\Models\Team;
use App\Services\Gateways\CheckoutIntents;

/**
 * Start a hosted checkout that verifies a card and tokenises it for API
 * clients, through whichever gateway the team has connected.
 */
class CreatePaymentMethodCheckoutSession
{
    private const int VERIFICATION_AMOUNT_MINOR = 10_000;

    public function __construct(
        private readonly CreateInvoice $createInvoice,
        private readonly GenerateInvoiceCheckout $generateCheckout,
    ) {
        //
    }

    /**
     * @return array{checkoutUrl: string, orderReference: string}
     */
    public function handle(Team $team, Customer $customer, ApiKeyMode $mode, bool $setDefault = true): array
    {
        $invoice = $this->createInvoice->handle(
            team: $team,
            customer: $customer,
            billingReason: InvoiceBillingReason::Manual,
            collectionMode: CollectionMode::Automatic,
            lines: [[
                'kind' => InvoiceLineKind::OneTime,
                'description' => 'Card verification',
                'unitAmount' => self::VERIFICATION_AMOUNT_MINOR,
                'quantity' => 1,
            ]],
        );

        $checkout = $this->generateCheckout->handle(
            team: $team,
            invoice: $invoice,
            tokenizeCard: true,
            allowedPaymentMethods: ['Card'],
            setDefaultPaymentMethod: $setDefault,
            mode: $mode,
        );

        $orderReference = $checkout['orderReference'];

        // Mark it API-initiated so the completion leg leaves a result the
        // client can still poll for after the intent itself is cleared.
        CheckoutIntents::merge($orderReference, ['api_checkout_session' => true]);

        return [
            'checkoutUrl' => $checkout['checkoutLink'],
            'orderReference' => $orderReference,
        ];
    }
}
