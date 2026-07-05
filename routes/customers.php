<?php

use App\Http\Controllers\Customers\AddressController;
use App\Http\Controllers\Customers\ChargeController;
use App\Http\Controllers\Customers\CustomerController;
use App\Http\Controllers\Customers\PaymentMethodController;
use App\Http\Middleware\EnsureCurrentTeam;
use Illuminate\Support\Facades\Route;

Route::prefix('customers')
    ->middleware(['auth', 'verified', EnsureCurrentTeam::class])
    ->name('customers.')
    ->group(function () {
        Route::get('/', [CustomerController::class, 'index'])->name('index');
        Route::post('/', [CustomerController::class, 'store'])->name('store');
        Route::post('bulk-archive', [CustomerController::class, 'bulkArchive'])->name('bulk-archive');

        // `show` and `restore` resolve archived (soft-deleted) customers so the
        // detail page can render the archived banner + Restore action.
        Route::get('{customer}', [CustomerController::class, 'show'])->withTrashed()->name('show');
        Route::patch('{customer}', [CustomerController::class, 'update'])->name('update');
        Route::delete('{customer}', [CustomerController::class, 'archive'])->name('archive');
        Route::post('{customer}/restore', [CustomerController::class, 'restore'])->withTrashed()->name('restore');

        Route::post('{customer}/addresses', [AddressController::class, 'store'])->name('addresses.store');
        Route::patch('{customer}/addresses/{address}', [AddressController::class, 'update'])->name('addresses.update');
        Route::delete('{customer}/addresses/{address}', [AddressController::class, 'destroy'])->name('addresses.destroy');
        Route::post('{customer}/addresses/{address}/default', [AddressController::class, 'makeDefault'])->name('addresses.default');

        Route::post('{customer}/payment-methods/{payment_method}/default', [PaymentMethodController::class, 'makeDefault'])->name('payment-methods.default');
        Route::delete('{customer}/payment-methods/{payment_method}', [PaymentMethodController::class, 'destroy'])->name('payment-methods.destroy');

        // Charge customer = create a Nomba checkout that tokenises the card as a
        // byproduct (CUSTOMERS_DESIGN §10.3). Test mode only for Phase 4.
        Route::post('{customer}/charge', [ChargeController::class, 'store'])->name('charge.store');
        Route::get('{customer}/charge/callback', [ChargeController::class, 'callback'])->name('charge.callback');
    });
