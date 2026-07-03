<?php

use App\Http\Controllers\Developers\ApiKeyController;
use App\Http\Controllers\Developers\NombaConnectionController;
use App\Http\Controllers\Developers\WebhookController;
use App\Http\Middleware\EnsureCurrentTeam;
use Illuminate\Support\Facades\Route;

Route::prefix('developers')
    ->middleware(['auth', 'verified', EnsureCurrentTeam::class])
    ->name('developers.')
    ->group(function () {
        Route::get('nomba', [NombaConnectionController::class, 'show'])->name('nomba.show');
        Route::post('nomba/connect', [NombaConnectionController::class, 'connect'])->name('nomba.connect');
        Route::post('nomba/test', [NombaConnectionController::class, 'test'])->name('nomba.test');
        Route::delete('nomba/disconnect', [NombaConnectionController::class, 'disconnect'])->name('nomba.disconnect');

        Route::get('api-keys', [ApiKeyController::class, 'index'])->name('api-keys.index');
        Route::post('api-keys', [ApiKeyController::class, 'store'])->name('api-keys.store');
        Route::delete('api-keys/{api_key}', [ApiKeyController::class, 'destroy'])->name('api-keys.destroy');

        Route::get('webhooks', [WebhookController::class, 'show'])->name('webhooks.show');
        Route::post('webhooks/secret', [WebhookController::class, 'saveSecret'])->name('webhooks.secret');
        Route::post('webhooks/rotate', [WebhookController::class, 'rotate'])->name('webhooks.rotate');
        Route::post('webhooks/test', [WebhookController::class, 'test'])->name('webhooks.test');
    });
