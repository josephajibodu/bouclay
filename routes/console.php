<?php

use App\Console\Commands\AdvanceSubscriptionPhases;
use App\Console\Commands\ApplyScheduledChanges;
use App\Console\Commands\BillSubscriptionRenewals;
use App\Console\Commands\DeliverPendingWebhooks;
use App\Console\Commands\ExpireIncompleteSubscriptions;
use App\Console\Commands\ProcessDunningRetries;
use App\Console\Commands\ProcessManualInvoiceDunningCommand;
use App\Models\TeamInvitation;
use Illuminate\Support\Facades\Schedule;

Schedule::call(function () {
    TeamInvitation::query()
        ->whereNotNull('expires_at')
        ->where('expires_at', '<', now())
        ->delete();
})->daily()->description('Delete expired team invitations');

Schedule::command(AdvanceSubscriptionPhases::class)->hourly();
Schedule::command(ApplyScheduledChanges::class)->hourly();
Schedule::command(BillSubscriptionRenewals::class)->hourly();
Schedule::command(DeliverPendingWebhooks::class)->everyMinute();
Schedule::command(ProcessDunningRetries::class)->hourly();
Schedule::command(ProcessManualInvoiceDunningCommand::class)->hourly();
Schedule::command(ExpireIncompleteSubscriptions::class)->hourly();
