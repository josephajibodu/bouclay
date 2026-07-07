<?php

namespace App\Actions\Dunning;

use App\Enums\DunningTerminalAction;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Subscription;

/**
 * Apply the configured terminal action when dunning retries are exhausted.
 */
class ApplyDunningExhaustion
{
    public function handle(Subscription $subscription, Invoice $openInvoice, DunningTerminalAction $action): void
    {
        match ($action) {
            DunningTerminalAction::Cancel => $this->closeOut($subscription, $openInvoice, 'cancel'),
            DunningTerminalAction::Pause => $this->closeOut($subscription, $openInvoice, 'pause'),
            DunningTerminalAction::LeaveOpen => null,
        };
    }

    private function closeOut(Subscription $subscription, Invoice $openInvoice, string $transition): void
    {
        if ($openInvoice->status === InvoiceStatus::Open) {
            $openInvoice->markUncollectible();
        }

        $subscription->apply($transition);
    }
}
