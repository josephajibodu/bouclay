<?php

use App\Console\Commands\ApplyScheduledChanges;
use App\Console\Commands\BillSubscriptionRenewals;
use App\Console\Commands\ConvertTrialSubscriptions;
use App\Models\TeamInvitation;
use Illuminate\Support\Facades\Schedule;

Schedule::call(function () {
    TeamInvitation::query()
        ->whereNotNull('expires_at')
        ->where('expires_at', '<', now())
        ->delete();
})->daily()->description('Delete expired team invitations');

Schedule::command(ConvertTrialSubscriptions::class)->hourly();
Schedule::command(ApplyScheduledChanges::class)->hourly();
Schedule::command(BillSubscriptionRenewals::class)->hourly();
