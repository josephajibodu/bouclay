<?php

use App\Http\Controllers\Developers\NombaConnectionController;
use App\Http\Middleware\EnsureTeamMembership;
use Illuminate\Support\Facades\Route;

Route::prefix('{current_team}/developers')
    ->middleware(['auth', 'verified', EnsureTeamMembership::class])
    ->name('developers.')
    ->group(function () {
        Route::get('nomba', [NombaConnectionController::class, 'show'])->name('nomba.show');
        Route::post('nomba/connect', [NombaConnectionController::class, 'connect'])->name('nomba.connect');
        Route::post('nomba/test', [NombaConnectionController::class, 'test'])->name('nomba.test');
        Route::delete('nomba/disconnect', [NombaConnectionController::class, 'disconnect'])->name('nomba.disconnect');
    });
