<?php

use App\Http\Controllers\Invoices\InvoiceController;
use App\Http\Middleware\EnsureCurrentTeam;
use Illuminate\Support\Facades\Route;

Route::prefix('invoices')
    ->middleware(['auth', 'verified', EnsureCurrentTeam::class])
    ->name('invoices.')
    ->group(function () {
        Route::get('/', [InvoiceController::class, 'index'])->name('index');
        Route::post('/', [InvoiceController::class, 'store'])->name('store');
        Route::get('{invoice}', [InvoiceController::class, 'show'])->name('show');
        Route::post('{invoice}/void', [InvoiceController::class, 'void'])->name('void');
        Route::post('{invoice}/uncollectible', [InvoiceController::class, 'markUncollectible'])->name('uncollectible');
    });
