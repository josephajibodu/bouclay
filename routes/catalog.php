<?php

use App\Http\Controllers\Catalog\EntitlementController;
use App\Http\Controllers\Catalog\GrantorEntitlementController;
use App\Http\Controllers\Catalog\PlanController;
use App\Http\Controllers\Catalog\PriceController;
use App\Http\Controllers\Catalog\ProductController;
use App\Http\Middleware\EnsureCurrentTeam;
use Illuminate\Support\Facades\Route;

Route::prefix('catalog')
    ->middleware(['auth', 'verified', EnsureCurrentTeam::class])
    ->name('catalog.')
    ->group(function () {
        Route::get('products', [ProductController::class, 'index'])->name('products.index');
        Route::get('products/create', [ProductController::class, 'create'])->name('products.create');
        Route::post('products', [ProductController::class, 'store'])->name('products.store');
        Route::get('products/{product}', [ProductController::class, 'show'])->name('products.show');
        Route::patch('products/{product}', [ProductController::class, 'update'])->name('products.update');

        Route::get('entitlements', [EntitlementController::class, 'index'])->name('entitlements.index');
        Route::post('entitlements', [EntitlementController::class, 'store'])->name('entitlements.store');
        Route::patch('entitlements/{entitlement}', [EntitlementController::class, 'update'])->name('entitlements.update');
        Route::delete('entitlements/{entitlement}', [EntitlementController::class, 'destroy'])->name('entitlements.destroy');
        Route::put('entitlements/{entitlement}/grants', [EntitlementController::class, 'grants'])->name('entitlements.grants');

        Route::post('products/{product}/plans', [PlanController::class, 'store'])->name('plans.store');
        Route::patch('products/{product}/plans/{plan}', [PlanController::class, 'update'])->name('plans.update');

        Route::put('products/{product}/entitlements', [GrantorEntitlementController::class, 'product'])->name('products.entitlements');
        Route::put('products/{product}/plans/{plan}/entitlements', [GrantorEntitlementController::class, 'plan'])->name('plans.entitlements');

        Route::post('products/{product}/prices', [PriceController::class, 'store'])->name('prices.store');
        Route::patch('products/{product}/prices/{price}', [PriceController::class, 'update'])->name('prices.update');
        Route::put('products/{product}/prices/{price}/phases', [PriceController::class, 'phases'])->name('prices.phases');
        Route::delete('products/{product}/prices/{price}', [PriceController::class, 'archive'])->name('prices.archive');
        Route::post('products/{product}/prices/{price}/payment-link', [PriceController::class, 'paymentLink'])->name('prices.payment-link');
    });
