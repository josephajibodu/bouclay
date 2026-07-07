<?php

namespace App\Console\Commands;

use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Support\DunningConfig;
use Illuminate\Console\Command;

class ExpireIncompleteSubscriptions extends Command
{
    protected $signature = 'subscriptions:expire-incomplete';

    protected $description = 'Expire incomplete subscriptions that exceeded the first-payment grace window';

    public function handle(): int
    {
        $count = 0;

        Subscription::query()
            ->where('status', SubscriptionStatus::Incomplete)
            ->with('team.settings')
            ->orderBy('id')
            ->each(function (Subscription $subscription) use (&$count): void {
                $graceDays = DunningConfig::forTeam($subscription->team)->incompleteGraceDays;
                $deadline = $subscription->created_at?->copy()->addDays($graceDays);

                if ($deadline === null || $deadline->isFuture()) {
                    return;
                }

                $subscription->apply('expire');
                $count++;
            });

        $this->info("Expired {$count} incomplete subscription(s).");

        return self::SUCCESS;
    }
}
