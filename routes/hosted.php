<?php

use App\Http\Controllers\Hosted\HostedInvoiceController;
use App\Http\Controllers\Hosted\PaymentLinkController;
use Illuminate\Support\Facades\Route;

Route::prefix('pay')->name('hosted.')->group(function () {
    Route::get('invoices/{publicId}', [HostedInvoiceController::class, 'show'])
        ->name('invoices.show');
    Route::post('invoices/{publicId}/pay', [HostedInvoiceController::class, 'pay'])
        ->name('invoices.pay');
    Route::get('callback', [HostedInvoiceController::class, 'callback'])
        ->name('checkout.callback');

    Route::get('links/{publicId}', [PaymentLinkController::class, 'show'])
        ->name('payment-links.show');
    Route::post('links/{publicId}/checkout', [PaymentLinkController::class, 'checkout'])
        ->name('payment-links.checkout');
});
