<?php

use App\Http\Controllers\Transactions\TransactionController;
use App\Http\Middleware\EnsureCurrentTeam;
use Illuminate\Support\Facades\Route;

Route::prefix('transactions')
    ->middleware(['auth', 'verified', EnsureCurrentTeam::class])
    ->name('transactions.')
    ->group(function () {
        Route::get('/', [TransactionController::class, 'index'])->name('index');
        Route::post('/', [TransactionController::class, 'store'])->name('store');
    });
