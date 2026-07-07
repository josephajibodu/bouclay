<?php

namespace App\Actions\Webhooks;

use App\Enums\OutboundEventType;
use App\Models\Invoice;
use App\Models\Payment;

/**
 * Emit an invoice.payment_failed outbound event.
 */
class EmitInvoicePaymentFailed
{
    public function __construct(
        private readonly EmitOutboundEvent $emit,
    ) {
        //
    }

    public function handle(Invoice $invoice, Payment $payment): void
    {
        $invoice->loadMissing(['customer', 'subscription', 'team']);
        $payment->loadMissing(['customer', 'invoice']);

        $this->emit->handle(
            $invoice->team,
            OutboundEventType::InvoicePaymentFailed,
            ['object' => array_merge(
                $invoice->toWebhookObject(),
                ['payment' => $payment->toWebhookObject()],
            )],
        );
    }
}
