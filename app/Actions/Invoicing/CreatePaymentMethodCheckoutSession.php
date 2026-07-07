<?php

namespace App\Actions\Invoicing;

use App\Enums\ApiKeyMode;
use App\Enums\CollectionMode;
use App\Enums\InvoiceBillingReason;
use App\Enums\InvoiceLineKind;
use App\Models\Customer;
use App\Models\Team;
use Illuminate\Support\Facades\Cache;

/**
 * Start a Nomba hosted checkout that verifies a card and tokenises it for API clients.
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

        /** @var array<string, mixed>|null $intent */
        $intent = Cache::get("nomba_checkout:{$orderReference}");

        if (is_array($intent)) {
            Cache::put("nomba_checkout:{$orderReference}", [
                ...$intent,
                'api_checkout_session' => true,
            ], now()->addDays(7));
        }

        return [
            'checkoutUrl' => $checkout['checkoutLink'],
            'orderReference' => $orderReference,
        ];
    }
}
