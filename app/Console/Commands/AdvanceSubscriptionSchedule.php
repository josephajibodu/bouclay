<?php

namespace App\Console\Commands;

use App\Actions\Subscriptions\AdvanceSubscriptionSchedule as AdvanceSchedule;
use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use Illuminate\Console\Command;

class AdvanceSubscriptionSchedule extends Command
{
    protected $signature = 'subscriptions:advance-schedule';

    protected $description = 'Advance subscriptions across subscription-schedule boundaries: convert due trials and step Pricing Journey schedules to their next step';

    public function handle(AdvanceSchedule $advance): int
    {
        // Candidates: trialing subscriptions whose clock has run out, plus
        // active subscriptions still threading a schedule whose current step
        // boundary has passed. The action re-checks each precisely.
        $due = Subscription::query()
            ->where(function ($query): void {
                $query->where(function ($query): void {
                    $query->where('status', SubscriptionStatus::Trialing)
                        ->whereNotNull('trial_ends_at')
                        ->where('trial_ends_at', '<=', now());
                })->orWhere(function ($query): void {
                    $query->where('status', SubscriptionStatus::Active)
                        ->whereNotNull('current_period_end')
                        ->where('current_period_end', '<=', now())
                        ->whereHas('items', fn ($items) => $items->whereNotNull('current_schedule_step_id'));
                });
            });

        $processed = 0;
        $invoiced = 0;

        $due->orderBy('id')
            ->each(function (Subscription $subscription) use ($advance, &$processed, &$invoiced): void {
                $processed++;

                if ($advance->handle($subscription) !== null) {
                    $invoiced++;
                }
            });

        $this->info("Processed {$processed} subscription(s); {$invoiced} billed after schedule advancement.");

        return self::SUCCESS;
    }
}
