<?php

namespace App\Console\Commands;

use App\Actions\Subscriptions\ApplyScheduledChange;
use App\Models\ScheduledChange;
use Illuminate\Console\Command;

class ApplyScheduledChanges extends Command
{
    protected $signature = 'subscriptions:apply-scheduled-changes';

    protected $description = 'Apply queued cancel, pause, and resume changes whose effective time has arrived';

    public function handle(ApplyScheduledChange $apply): int
    {
        $count = 0;

        ScheduledChange::query()
            ->whereNull('applied_at')
            ->where('effective_at', '<=', now())
            ->orderBy('id')
            ->each(function (ScheduledChange $change) use ($apply, &$count): void {
                if ($apply->handle($change)) {
                    $count++;
                }
            });

        $this->info("Applied {$count} scheduled change(s).");

        return self::SUCCESS;
    }
}
