<?php

namespace App\Actions\Dunning;

use App\Enums\CollectionMode;
use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Support\DunningConfig;

/**
 * Build dunning metadata for subscription hub and list views.
 */
class ResolveSubscriptionDunning
{
    public function __construct(
        private readonly RetryPastDueInvoice $retryPastDueInvoice,
        private readonly ProcessManualInvoiceDunning $manualInvoiceDunning,
        private readonly ResolveOpenRenewalInvoice $resolveOpenRenewalInvoice,
    ) {
        //
    }

    /**
     * @return array{
     *     attempt: int|null,
     *     maxAttempts: int|null,
     *     nextRetryAt: string|null,
     *     canRetryNow: bool,
     * }|null
     */
    public function handle(Subscription $subscription): ?array
    {
        if ($subscription->status !== SubscriptionStatus::PastDue) {
            return null;
        }

        $subscription->loadMissing('team.settings');

        $config = DunningConfig::forTeam($subscription->team);
        $invoice = $this->resolveOpenRenewalInvoice->handle($subscription);

        if ($invoice === null) {
            return [
                'attempt' => null,
                'maxAttempts' => $config->maxAttempts,
                'nextRetryAt' => null,
                'canRetryNow' => false,
            ];
        }

        if ($subscription->collection_mode === CollectionMode::Manual) {
            $summary = $this->manualInvoiceDunning->summarize($invoice, $config);

            return [
                'attempt' => min($summary['reminderCount'] + 1, $config->maxAttempts),
                'maxAttempts' => $summary['maxAttempts'],
                'nextRetryAt' => $summary['nextReminderAt']?->toISOString(),
                'canRetryNow' => false,
            ];
        }

        $summary = $this->retryPastDueInvoice->summarize($invoice, $config);

        return [
            'attempt' => $summary['attemptCount'],
            'maxAttempts' => $summary['maxAttempts'],
            'nextRetryAt' => $summary['nextRetryAt']?->toISOString(),
            'canRetryNow' => ! $summary['shouldExhaust']
                && ($summary['nextRetryAt'] === null || $summary['nextRetryAt']->isPast()),
        ];
    }
}
