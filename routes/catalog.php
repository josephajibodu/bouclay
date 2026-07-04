<?php

use App\Http\Controllers\Catalog\PriceController;
use App\Http\Controllers\Catalog\ProductController;
use App\Http\Controllers\Catalog\TrialOfferController;
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

        Route::post('products/{product}/prices', [PriceController::class, 'store'])->name('prices.store');
        Route::patch('products/{product}/prices/{price}', [PriceController::class, 'update'])->name('prices.update');
        Route::delete('products/{product}/prices/{price}', [PriceController::class, 'archive'])->name('prices.archive');

        Route::post('products/{product}/trials', [TrialOfferController::class, 'store'])->name('trials.store');
        Route::patch('products/{product}/trials/{trial_offer}', [TrialOfferController::class, 'update'])->name('trials.update');
        Route::delete('products/{product}/trials/{trial_offer}', [TrialOfferController::class, 'destroy'])->name('trials.destroy');
    });
