<?php

use App\Http\Controllers\Discounts\DiscountController;
use App\Http\Middleware\EnsureCurrentTeam;
use Illuminate\Support\Facades\Route;

Route::prefix('discounts')
    ->middleware(['auth', 'verified', EnsureCurrentTeam::class])
    ->name('discounts.')
    ->group(function () {
        Route::get('/', [DiscountController::class, 'index'])->name('index');
        Route::post('/', [DiscountController::class, 'store'])->name('store');
        Route::patch('{discount}', [DiscountController::class, 'update'])->name('update');
        Route::delete('{discount}', [DiscountController::class, 'destroy'])->name('destroy');
    });
