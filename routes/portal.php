<?php

use App\Http\Controllers\Portal\PortalController;
use App\Http\Controllers\Portal\PortalPaymentMethodController;
use App\Http\Controllers\Portal\PortalSubscriptionController;
use Illuminate\Support\Facades\Route;

Route::prefix('portal')->name('portal.')->group(function () {
    Route::get('{token}', [PortalController::class, 'show'])
        ->name('show');

    Route::get('{token}/subscriptions', [PortalController::class, 'subscriptions'])
        ->name('subscriptions.index');

    Route::get('{token}/subscriptions/{publicId}', [PortalController::class, 'subscription'])
        ->name('subscriptions.show');

    Route::get('{token}/payments', [PortalController::class, 'payments'])
        ->name('payments.index');

    Route::get('{token}/payment-methods', [PortalController::class, 'paymentMethods'])
        ->name('payment-methods.index');

    Route::get('{token}/account', [PortalController::class, 'account'])
        ->name('account.index');

    Route::post('{token}/payment-method', [PortalPaymentMethodController::class, 'store'])
        ->name('payment-method.store');

    Route::get('{token}/payment-method/callback', [PortalPaymentMethodController::class, 'callback'])
        ->name('payment-method.callback');

    Route::post('{token}/subscriptions/{publicId}/cancel', [PortalSubscriptionController::class, 'cancel'])
        ->name('subscriptions.cancel');
});
