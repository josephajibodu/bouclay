<?php

use App\Http\Controllers\Portal\PortalController;
use Illuminate\Support\Facades\Route;

Route::prefix('portal')->name('portal.')->group(function () {
    Route::get('{token}', [PortalController::class, 'show'])
        ->name('show');
});
