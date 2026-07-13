<?php

namespace App\Console\Commands;

use App\Actions\Subscriptions\AdvanceSubscriptionPhases as AdvancePhases;
use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use Illuminate\Console\Command;

class AdvanceSubscriptionPhases extends Command
{
    protected $signature = 'subscriptions:advance-phases';

    protected $description = 'Advance subscriptions across price-phase boundaries: convert due trials and step paid ramps to their next phase';

    public function handle(AdvancePhases $advance): int
    {
        // Candidates: trialing subscriptions whose clock has run out, plus
        // active subscriptions still threading a phased ramp whose current
        // phase boundary has passed. The action re-checks each precisely.
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
                        ->whereHas('items', fn ($items) => $items->whereNotNull('current_phase_sequence'));
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

        $this->info("Processed {$processed} subscription(s); {$invoiced} billed after phase advancement.");

        return self::SUCCESS;
    }
}
