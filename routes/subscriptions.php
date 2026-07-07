<?php

use App\Http\Controllers\Subscriptions\SubscriptionController;
use App\Http\Middleware\EnsureCurrentTeam;
use Illuminate\Support\Facades\Route;

Route::prefix('subscriptions')
    ->middleware(['auth', 'verified', EnsureCurrentTeam::class])
    ->name('subscriptions.')
    ->group(function () {
        Route::get('/', [SubscriptionController::class, 'index'])->name('index');
        Route::post('/', [SubscriptionController::class, 'store'])->name('store');

        Route::get('{subscription}', [SubscriptionController::class, 'show'])->name('show');
        Route::post('{subscription}/items/{item}', [SubscriptionController::class, 'updateItem'])->name('items.update');
        Route::post('{subscription}/pause', [SubscriptionController::class, 'pause'])->name('pause');
        Route::post('{subscription}/resume', [SubscriptionController::class, 'resume'])->name('resume');
        Route::post('{subscription}/cancel', [SubscriptionController::class, 'cancel'])->name('cancel');
        Route::post('{subscription}/undo-cancel', [SubscriptionController::class, 'resumeSchedule'])->name('undo-cancel');
    });
