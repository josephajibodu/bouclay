<?php

namespace App\Actions\Invoicing;

use App\Enums\ApiKeyMode;
use App\Models\Invoice;
use App\Models\Team;
use App\Services\Gateways\CheckoutIntents;
use App\Services\Gateways\GatewayException;
use App\Services\Gateways\GatewayManager;
use App\Services\Gateways\GatewayModeResolver;
use App\Services\Gateways\GatewayOrder;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Create a hosted checkout order tied to an open invoice, through the team's
 * gateway driver. New checkouts route through the team's default connection
 * (schema.md routing rule — `is_default` governs new checkouts; a stored card
 * is a different question, answered by the token's own processor).
 */
class GenerateInvoiceCheckout
{
    public function __construct(
        private readonly GatewayManager $gateways,
        private readonly GatewayModeResolver $modeResolver,
    ) {
        //
    }

    /**
     * @return array{checkoutLink: string, orderReference: string}
     *
     * @throws InvalidArgumentException
     * @throws GatewayException
     */
    public function handle(
        Team $team,
        Invoice $invoice,
        bool $tokenizeCard = false,
        bool $cardOnly = false,
        bool $setDefaultPaymentMethod = false,
        ?ApiKeyMode $mode = null,
    ): array {
        $invoice->loadMissing('customer');

        if (! $invoice->canBeCanceled()) {
            throw new InvalidArgumentException('This invoice can no longer be paid.');
        }

        $connection = $team->processorConnection;
        $mode ??= $this->modeResolver->forConnection($connection);

        if ($connection === null || $mode === null) {
            throw new InvalidArgumentException('Connect a payment gateway before collecting payment for this invoice.');
        }

        $gateway = $this->gateways->forConnection($connection);

        $orderReference = (string) Str::uuid();

        $result = $gateway->createCheckout($connection, $mode, new GatewayOrder(
            reference: $orderReference,
            customerEmail: $invoice->customer_snapshot['email'] ?? $invoice->customer->email,
            amountMinor: $invoice->total,
            currency: $invoice->currency,
            customerReference: $invoice->customer->public_id,
            callbackUrl: route('hosted.checkout.callback', ['orderReference' => $orderReference]),
            cardOnly: $cardOnly,
        ), $tokenizeCard);

        CheckoutIntents::put($orderReference, [
            'invoice_id' => $invoice->id,
            'customer_id' => $invoice->customer_id,
            'team_id' => $team->id,
            'mode' => $mode->value,
            'tokenize_card' => $tokenizeCard,
            'set_default' => $setDefaultPaymentMethod,
        ]);

        $invoice->forceFill([
            'custom_data' => array_merge($invoice->custom_data ?? [], [
                'checkout_link' => $result['checkoutLink'],
                'checkout_order_reference' => $orderReference,
            ]),
        ])->save();

        return [
            'checkoutLink' => $result['checkoutLink'],
            'orderReference' => $orderReference,
        ];
    }
}
