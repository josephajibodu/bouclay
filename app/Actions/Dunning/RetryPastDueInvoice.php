<?php

namespace App\Actions\Dunning;

use App\Actions\Invoicing\ChargeInvoice;
use App\Actions\Invoicing\SettleSubscriptionOnInvoicePayment;
use App\Enums\CollectionMode;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Subscription;
use App\Services\Invoicing\ClassifyPaymentFailure;
use App\Support\DunningConfig;
use Illuminate\Support\Carbon;

/**
 * Retry collection on a past-due subscription's open renewal invoice.
 */
class RetryPastDueInvoice
{
    public function __construct(
        private readonly ChargeInvoice $chargeInvoice,
        private readonly SettleSubscriptionOnInvoicePayment $settleSubscription,
        private readonly ClassifyPaymentFailure $classifyFailure,
        private readonly ApplyDunningExhaustion $applyExhaustion,
        private readonly ResolveOpenRenewalInvoice $resolveOpenRenewalInvoice,
    ) {
        //
    }

    /**
     * @return 'retried'|'recovered'|'exhausted'|'skipped'
     */
    public function handle(Subscription $subscription, bool $force = false): string
    {
        if ($subscription->status !== SubscriptionStatus::PastDue) {
            return 'skipped';
        }

        if ($subscription->collection_mode !== CollectionMode::Automatic) {
            return 'skipped';
        }

        $invoice = $this->resolveOpenRenewalInvoice->handle($subscription);

        if ($invoice === null) {
            return 'skipped';
        }

        $config = DunningConfig::forTeam($subscription->team);
        $summary = $this->summarize($invoice, $config);

        if ($summary['shouldExhaust']) {
            $this->applyExhaustion->handle($subscription, $invoice, $config->terminalAction);

            return 'exhausted';
        }

        if (! $force && $summary['nextRetryAt'] !== null && $summary['nextRetryAt']->isFuture()) {
            return 'skipped';
        }

        $paymentMethod = $subscription->paymentMethod;

        if (! $paymentMethod instanceof PaymentMethod) {
            return 'skipped';
        }

        $attemptNumber = $summary['attemptCount'] + 1;
        $payment = $this->chargeInvoice->handle(
            $subscription->team,
            $invoice,
            $paymentMethod,
            $attemptNumber,
        );

        if ($payment->status === PaymentStatus::Succeeded) {
            $this->settleSubscription->onPaymentSucceeded($invoice);

            return 'recovered';
        }

        $updatedSummary = $this->summarize($invoice->fresh(['payments']), $config);

        if ($updatedSummary['shouldExhaust']) {
            $this->applyExhaustion->handle($subscription->fresh(), $invoice->fresh(), $config->terminalAction);

            return 'exhausted';
        }

        return 'retried';
    }

    /**
     * @return array{
     *     attemptCount: int,
     *     maxAttempts: int,
     *     nextRetryAt: Carbon|null,
     *     shouldExhaust: bool,
     *     latestFailureCode: string|null,
     * }
     */
    public function summarize(Invoice $invoice, DunningConfig $config): array
    {
        $invoice->loadMissing(['customer', 'payments']);

        $attemptCount = $invoice->payments->count();
        $latestPayment = $invoice->payments->sortByDesc('attempt_number')->first();
        $failedCount = $invoice->payments
            ->where('status', PaymentStatus::Failed)
            ->count();

        $latestFailureCode = $latestPayment instanceof Payment
            && $latestPayment->status === PaymentStatus::Failed
            ? $latestPayment->failure_code
            : null;

        $isHardDecline = $this->classifyFailure->isHardDecline($latestFailureCode);
        $shouldExhaust = $attemptCount >= $config->maxAttempts
            || ($isHardDecline && $failedCount >= 1);

        $nextRetryAt = null;

        if (! $shouldExhaust && $latestPayment instanceof Payment && $latestPayment->status === PaymentStatus::Failed) {
            $days = $config->retryIntervalDaysAfterAttempt($failedCount);
            $nextRetryAt = $latestPayment->created_at?->copy()->addDays($days);
        }

        return [
            'attemptCount' => $attemptCount,
            'maxAttempts' => $config->maxAttempts,
            'nextRetryAt' => $nextRetryAt,
            'shouldExhaust' => $shouldExhaust,
            'latestFailureCode' => $latestFailureCode,
        ];
    }
}
