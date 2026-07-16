<?php

namespace App\Actions\Webhooks;

use App\Enums\OutboundEventType;
use App\Models\Invoice;
use App\Models\Payment;

/**
 * Announce a failed charge attempt as an `invoice.updated` — the invoice's
 * own status is unchanged (it stays open for a retry), so the payload carries
 * the failed `payment` alongside it and consumers read the outcome there.
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
            OutboundEventType::InvoiceUpdated,
            ['object' => array_merge(
                $invoice->toWebhookObject(),
                ['payment' => $payment->toWebhookObject()],
            )],
        );
    }
}
