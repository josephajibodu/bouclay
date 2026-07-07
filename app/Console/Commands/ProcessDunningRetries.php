<?php

namespace App\Console\Commands;

use App\Actions\Dunning\RetryPastDueInvoice;
use App\Enums\CollectionMode;
use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use Illuminate\Console\Command;

class ProcessDunningRetries extends Command
{
    protected $signature = 'subscriptions:process-dunning';

    protected $description = 'Retry failed renewal charges for past-due subscriptions on the dunning schedule';

    public function handle(RetryPastDueInvoice $retry): int
    {
        $counts = [
            'retried' => 0,
            'recovered' => 0,
            'exhausted' => 0,
            'skipped' => 0,
        ];

        Subscription::query()
            ->where('status', SubscriptionStatus::PastDue)
            ->where('collection_mode', CollectionMode::Automatic)
            ->with(['team.settings', 'paymentMethod'])
            ->orderBy('id')
            ->each(function (Subscription $subscription) use ($retry, &$counts): void {
                $result = $retry->handle($subscription);
                $counts[$result]++;
            });

        $this->info(sprintf(
            'Dunning: %d retried, %d recovered, %d exhausted, %d skipped.',
            $counts['retried'],
            $counts['recovered'],
            $counts['exhausted'],
            $counts['skipped'],
        ));

        return self::SUCCESS;
    }
}
