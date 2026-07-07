<?php

namespace App\Actions\Dunning;

use App\Enums\CollectionMode;
use App\Enums\InvoiceStatus;
use App\Enums\SubscriptionStatus;
use App\Mail\InvoiceIssued;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Support\DunningConfig;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

/**
 * Reminder-based dunning for manual-collection subscriptions — ages overdue
 * renewal invoices to {@see SubscriptionStatus::PastDue}, sends payment
 * reminders on the team's schedule, then applies the terminal action.
 */
class ProcessManualInvoiceDunning
{
    public function __construct(
        private readonly ResolveOpenRenewalInvoice $resolveOpenRenewalInvoice,
        private readonly ApplyDunningExhaustion $applyExhaustion,
    ) {
        //
    }

    /**
     * @return 'aged'|'reminded'|'exhausted'|'skipped'
     */
    public function handle(Subscription $subscription): string
    {
        if ($subscription->collection_mode !== CollectionMode::Manual) {
            return 'skipped';
        }

        $invoice = $this->resolveOpenRenewalInvoice->handle($subscription);

        if ($invoice === null || $invoice->status !== InvoiceStatus::Open) {
            return 'skipped';
        }

        if ($subscription->status === SubscriptionStatus::Active) {
            return $this->ageOverdueInvoice($subscription, $invoice);
        }

        if ($subscription->status !== SubscriptionStatus::PastDue) {
            return 'skipped';
        }

        return $this->remindOrExhaust($subscription, $invoice);
    }

    /**
     * @return array{
     *     reminderCount: int,
     *     maxAttempts: int,
     *     nextReminderAt: Carbon|null,
     *     shouldExhaust: bool,
     * }
     */
    public function summarize(Invoice $invoice, DunningConfig $config): array
    {
        $reminderCount = (int) ($invoice->custom_data['dunning_reminder_count'] ?? 0);
        $lastReminderAt = $this->parseTimestamp($invoice->custom_data['last_dunning_reminder_at'] ?? null);
        $shouldExhaust = $reminderCount >= $config->maxAttempts;

        $nextReminderAt = null;

        if (! $shouldExhaust) {
            $anchor = $lastReminderAt ?? $invoice->due_at;

            if ($anchor !== null) {
                $days = $config->retryIntervalDaysAfterAttempt(max(1, $reminderCount));
                $nextReminderAt = $anchor->copy()->addDays($days);
            }
        }

        return [
            'reminderCount' => $reminderCount,
            'maxAttempts' => $config->maxAttempts,
            'nextReminderAt' => $nextReminderAt,
            'shouldExhaust' => $shouldExhaust,
        ];
    }

    private function ageOverdueInvoice(Subscription $subscription, Invoice $invoice): string
    {
        if ($invoice->due_at === null || $invoice->due_at->isFuture()) {
            return 'skipped';
        }

        $subscription->apply('markPastDue');

        return 'aged';
    }

    private function remindOrExhaust(Subscription $subscription, Invoice $invoice): string
    {
        $config = DunningConfig::forTeam($subscription->team);
        $summary = $this->summarize($invoice, $config);

        if ($summary['shouldExhaust']) {
            $this->applyExhaustion->handle($subscription, $invoice, $config->terminalAction);

            return 'exhausted';
        }

        if ($summary['nextReminderAt'] !== null && $summary['nextReminderAt']->isFuture()) {
            return 'skipped';
        }

        $this->sendReminder($invoice);

        $reminderCount = $summary['reminderCount'] + 1;
        $invoice->forceFill([
            'custom_data' => array_merge($invoice->custom_data ?? [], [
                'dunning_reminder_count' => $reminderCount,
                'last_dunning_reminder_at' => now()->toIso8601String(),
            ]),
        ])->save();

        if ($reminderCount >= $config->maxAttempts) {
            $this->applyExhaustion->handle($subscription->fresh(), $invoice->fresh(), $config->terminalAction);

            return 'exhausted';
        }

        return 'reminded';
    }

    private function sendReminder(Invoice $invoice): void
    {
        $invoice->loadMissing(['customer', 'team']);

        $email = $invoice->customer_snapshot['email'] ?? $invoice->customer->email;
        $hostedUrl = route('hosted.invoices.show', $invoice->public_id);

        Mail::to($email)->queue(new InvoiceIssued($invoice, $hostedUrl, 'Pay overdue invoice'));
    }

    private function parseTimestamp(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return Carbon::parse($value);
    }
}
