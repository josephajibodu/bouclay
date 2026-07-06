<?php

namespace App\Console\Commands;

use App\Actions\Subscriptions\RenewSubscription;
use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use Illuminate\Console\Command;

class BillSubscriptionRenewals extends Command
{
    protected $signature = 'subscriptions:bill-renewals';

    protected $description = 'Generate renewal invoices for subscriptions whose billing period has ended';

    public function handle(RenewSubscription $renew): int
    {
        $count = 0;

        Subscription::query()
            ->whereIn('status', [SubscriptionStatus::Active, SubscriptionStatus::PastDue])
            ->whereNotNull('current_period_end')
            ->where('current_period_end', '<=', now())
            ->orderBy('id')
            ->each(function (Subscription $subscription) use ($renew, &$count): void {
                $invoice = $renew->handle($subscription);

                if ($invoice !== null) {
                    $count++;
                }
            });

        $this->info("Renewed {$count} subscription(s).");

        return self::SUCCESS;
    }
}
