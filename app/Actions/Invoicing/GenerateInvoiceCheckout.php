<?php

namespace App\Actions\Invoicing;

use App\Enums\ApiKeyMode;
use App\Exceptions\Nomba\NombaConnectionException;
use App\Models\Invoice;
use App\Models\Team;
use App\Services\Nomba\NombaCheckout;
use App\Services\Nomba\NombaModeResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Create a Nomba hosted checkout order tied to an open invoice.
 */
class GenerateInvoiceCheckout
{
    public function __construct(
        private readonly NombaCheckout $checkout,
        private readonly NombaModeResolver $modeResolver,
    ) {
        //
    }

    /**
     * @param  list<string>|null  $allowedPaymentMethods
     * @return array{checkoutLink: string, orderReference: string}
     *
     * @throws InvalidArgumentException
     * @throws NombaConnectionException
     */
    public function handle(
        Team $team,
        Invoice $invoice,
        bool $tokenizeCard = false,
        ?array $allowedPaymentMethods = null,
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
            throw new InvalidArgumentException('Connect Nomba before collecting payment for this invoice.');
        }

        $orderReference = (string) Str::uuid();

        $order = [
            'amount' => number_format($invoice->total / 100, 2, '.', ''),
            'currency' => $invoice->currency,
            'orderReference' => $orderReference,
            'customerId' => $invoice->customer->public_id,
            'customerEmail' => $invoice->customer_snapshot['email'] ?? $invoice->customer->email,
            'callbackUrl' => route('hosted.checkout.callback', ['orderReference' => $orderReference]),
        ];

        if ($allowedPaymentMethods !== null) {
            $order['allowedPaymentMethods'] = $allowedPaymentMethods;
        }

        $result = $this->checkout->createOrder($connection, $mode, $order, $tokenizeCard);

        Cache::put("nomba_checkout:{$orderReference}", [
            'invoice_id' => $invoice->id,
            'customer_id' => $invoice->customer_id,
            'team_id' => $team->id,
            'mode' => $mode->value,
            'tokenize_card' => $tokenizeCard,
            'set_default' => $setDefaultPaymentMethod,
        ], now()->addDays(7));

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
