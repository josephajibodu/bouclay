<?php

namespace App\Actions\Dunning;

use App\Enums\InvoiceBillingReason;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Subscription;

/**
 * Find the open subscription-cycle invoice a dunning worker should act on.
 */
class ResolveOpenRenewalInvoice
{
    public function handle(Subscription $subscription): ?Invoice
    {
        if ($subscription->relationLoaded('invoices')) {
            $invoice = $subscription->invoices
                ->where('status', InvoiceStatus::Open)
                ->where('billing_reason', InvoiceBillingReason::SubscriptionCycle)
                ->sortByDesc('id')
                ->first();

            if ($invoice instanceof Invoice) {
                return $invoice;
            }
        }

        return $subscription->invoices()
            ->where('status', InvoiceStatus::Open)
            ->where('billing_reason', InvoiceBillingReason::SubscriptionCycle)
            ->orderByDesc('id')
            ->first();
    }
}
