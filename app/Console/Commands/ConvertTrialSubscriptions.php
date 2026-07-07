<?php

namespace App\Console\Commands;

use App\Actions\Subscriptions\ConvertSubscription;
use App\Enums\SubscriptionItemTrialStatus;
use App\Models\Subscription;
use App\Models\SubscriptionItemTrial;
use Illuminate\Console\Command;

class ConvertTrialSubscriptions extends Command
{
    protected $signature = 'subscriptions:convert-trials';

    protected $description = 'Convert expired item trials and bill the first cycle when a free trial ends';

    public function handle(ConvertSubscription $convert): int
    {
        $subscriptionIds = SubscriptionItemTrial::query()
            ->where('subscription_item_trials.status', SubscriptionItemTrialStatus::Active)
            ->where('subscription_item_trials.ends_at', '<=', now())
            ->join('subscription_items', 'subscription_items.id', '=', 'subscription_item_trials.subscription_item_id')
            ->distinct()
            ->pluck('subscription_items.subscription_id');

        $invoiced = 0;

        Subscription::query()
            ->whereIn('id', $subscriptionIds)
            ->orderBy('id')
            ->each(function (Subscription $subscription) use ($convert, &$invoiced): void {
                if ($convert->handle($subscription) !== null) {
                    $invoiced++;
                }
            });

        $this->info("Processed {$subscriptionIds->count()} subscription(s); {$invoiced} billed after trial conversion.");

        return self::SUCCESS;
    }
}
