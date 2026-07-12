<?php

namespace App\Console\Commands;

use App\Actions\Subscriptions\ConvertSubscription;
use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use Illuminate\Console\Command;

class ConvertTrialSubscriptions extends Command
{
    protected $signature = 'subscriptions:convert-trials';

    protected $description = 'Convert trialing subscriptions whose trial has ended and bill the first cycle';

    public function handle(ConvertSubscription $convert): int
    {
        $due = Subscription::query()
            ->where('status', SubscriptionStatus::Trialing)
            ->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '<=', now());

        $processed = 0;
        $invoiced = 0;

        $due->orderBy('id')
            ->each(function (Subscription $subscription) use ($convert, &$processed, &$invoiced): void {
                $processed++;

                if ($convert->handle($subscription) !== null) {
                    $invoiced++;
                }
            });

        $this->info("Processed {$processed} subscription(s); {$invoiced} billed after trial conversion.");

        return self::SUCCESS;
    }
}
