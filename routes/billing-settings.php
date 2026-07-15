<?php

use App\Http\Controllers\BillingSettingsController;
use App\Http\Middleware\EnsureCurrentTeam;
use Illuminate\Support\Facades\Route;

Route::prefix('billing-settings')
    ->middleware(['auth', 'verified', EnsureCurrentTeam::class])
    ->name('billing-settings.')
    ->group(function () {
        Route::get('/', [BillingSettingsController::class, 'show'])->name('show');
        Route::put('dunning', [BillingSettingsController::class, 'updateDunning'])->name('dunning.update');
    });
