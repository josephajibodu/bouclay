<?php

namespace App\Actions\Invoicing;

use App\Enums\InvoiceBillingReason;
use App\Enums\SubscriptionStatus;
use App\Models\Invoice;

/**
 * Apply subscription lifecycle transitions when an invoice payment succeeds or
 * an automatic renewal charge fails.
 */
class SettleSubscriptionOnInvoicePayment
{
    public function onPaymentSucceeded(Invoice $invoice): void
    {
        $subscription = $invoice->subscription;

        if ($subscription === null) {
            return;
        }

        match ($subscription->status) {
            SubscriptionStatus::Incomplete => $subscription->apply('activate'),
            SubscriptionStatus::PastDue => $subscription->apply('recover'),
            default => null,
        };
    }

    public function onAutomaticChargeFailed(Invoice $invoice): void
    {
        if ($invoice->billing_reason !== InvoiceBillingReason::SubscriptionCycle) {
            return;
        }

        $subscription = $invoice->subscription;

        if ($subscription === null || $subscription->status !== SubscriptionStatus::Active) {
            return;
        }

        $subscription->apply('markPastDue');
    }
}
