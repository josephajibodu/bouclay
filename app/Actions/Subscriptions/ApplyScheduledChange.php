<?php

namespace App\Actions\Subscriptions;

use App\Enums\ScheduledChangeAction;
use App\Models\ScheduledChange;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Apply a queued cancel/pause/resume when its effective time arrives.
 */
class ApplyScheduledChange
{
    public function handle(ScheduledChange $change): bool
    {
        if ($change->applied_at !== null) {
            return false;
        }

        if ($change->effective_at->isFuture()) {
            return false;
        }

        $subscription = $change->subscription()->firstOrFail();

        DB::transaction(function () use ($change, $subscription): void {
            match ($change->action) {
                ScheduledChangeAction::Cancel => $subscription->apply('cancel', $change->effective_at),
                ScheduledChangeAction::Pause => $subscription->apply(
                    'pause',
                    isset($change->payload['resumes_at'])
                        ? Carbon::parse((string) $change->payload['resumes_at'])
                        : null,
                ),
                ScheduledChangeAction::Resume => $subscription->apply('resume'),
            };

            $change->update(['applied_at' => Carbon::now()]);
        });

        return true;
    }
}
