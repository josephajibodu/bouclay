<?php

namespace App\Console\Commands;

use App\Actions\Dunning\ProcessManualInvoiceDunning;
use App\Enums\CollectionMode;
use App\Enums\InvoiceBillingReason;
use App\Enums\InvoiceStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use Illuminate\Console\Command;

class ProcessManualInvoiceDunningCommand extends Command
{
    protected $signature = 'subscriptions:process-manual-dunning';

    protected $description = 'Age overdue manual renewal invoices, send reminders, and apply terminal actions';

    public function handle(ProcessManualInvoiceDunning $process): int
    {
        $counts = [
            'aged' => 0,
            'reminded' => 0,
            'exhausted' => 0,
            'skipped' => 0,
        ];

        Subscription::query()
            ->whereIn('status', [SubscriptionStatus::Active, SubscriptionStatus::PastDue])
            ->where('collection_mode', CollectionMode::Manual)
            ->whereHas('invoices', fn ($query) => $query
                ->where('status', InvoiceStatus::Open)
                ->where('billing_reason', InvoiceBillingReason::SubscriptionCycle))
            ->with(['team.settings', 'invoices'])
            ->orderBy('id')
            ->each(function (Subscription $subscription) use ($process, &$counts): void {
                $result = $process->handle($subscription);
                $counts[$result]++;
            });

        $this->info(sprintf(
            'Manual dunning: %d aged, %d reminded, %d exhausted, %d skipped.',
            $counts['aged'],
            $counts['reminded'],
            $counts['exhausted'],
            $counts['skipped'],
        ));

        return self::SUCCESS;
    }
}
