<?php

use App\Http\Controllers\Auth\JoinInvitationController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Teams\TeamInvitationController;
use App\Http\Controllers\Webhooks\NombaInboundController;
use App\Http\Middleware\EnsureCurrentTeam;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::get('dashboard', DashboardController::class)
    ->middleware(['auth', 'verified', EnsureCurrentTeam::class])
    ->name('dashboard');

Route::middleware(['auth'])->group(function () {
    Route::post('invitations/{invitation}/accept', [TeamInvitationController::class, 'accept'])->name('invitations.accept');
    Route::delete('invitations/{invitation}', [TeamInvitationController::class, 'decline'])->name('invitations.decline');
});

// Invite-only onboarding — separate from Fortify's registration flow so an
// invited user is never routed through business-creation forms.
Route::get('join/{invitation}', [JoinInvitationController::class, 'show'])->name('join.show');
Route::post('join/{invitation}/decline', [JoinInvitationController::class, 'decline'])->name('join.decline');

Route::middleware('guest')->group(function () {
    Route::get('join/{invitation}/register', [JoinInvitationController::class, 'showRegister'])->name('join.register');
    Route::post('join/{invitation}/register', [JoinInvitationController::class, 'register'])->name('join.register.store');
});

Route::post('webhooks/nomba/{token}', NombaInboundController::class)->name('webhooks.nomba.receive');

Route::post('ingres/qydaD5iz2W0V2bRPTaqlTJYVaiR2zLAd', function () {
    Log::info('Incoming webhook: ', request()->all());

    return response()->json([
        'message' => 'All is well and good',
        'data' => null,
    ]);
});

require __DIR__.'/settings.php';
require __DIR__.'/developers.php';
require __DIR__.'/catalog.php';
