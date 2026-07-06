<?php

use App\Http\Controllers\Hosted\HostedInvoiceController;
use Illuminate\Support\Facades\Route;

Route::prefix('pay')->name('hosted.')->group(function () {
    Route::get('invoices/{publicId}', [HostedInvoiceController::class, 'show'])
        ->name('invoices.show');
    Route::post('invoices/{publicId}/pay', [HostedInvoiceController::class, 'pay'])
        ->name('invoices.pay');
    Route::get('callback', [HostedInvoiceController::class, 'callback'])
        ->name('checkout.callback');
});
