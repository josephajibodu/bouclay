<?php

use App\Http\Controllers\Settings\GeneralController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\Teams\RoleController;
use App\Http\Controllers\Teams\TeamController;
use App\Http\Controllers\Teams\TeamInvitationController;
use App\Http\Controllers\Teams\TeamMemberController;
use App\Http\Middleware\EnsureCurrentTeam;
use App\Http\Middleware\EnsureTeamMembership;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::inertia('settings/appearance', 'settings/appearance')->name('appearance.edit');

    // Businesses (teams) the user belongs to — list, create, switch, leave, delete.
    Route::get('settings/businesses', [TeamController::class, 'index'])->name('teams.index');
    Route::post('settings/businesses', [TeamController::class, 'store'])->name('teams.store');

    Route::middleware(EnsureTeamMembership::class)->group(function () {
        Route::post('settings/businesses/{team}/switch', [TeamController::class, 'switch'])->name('teams.switch');
        Route::delete('settings/businesses/{team}/leave', [TeamController::class, 'leave'])->name('teams.leave');
        Route::delete('settings/businesses/{team}', [TeamController::class, 'destroy'])->name('teams.destroy');
    });

    // Business details and team members always act on the user's current team.
    Route::middleware(EnsureCurrentTeam::class)->group(function () {
        Route::get('settings/general', [GeneralController::class, 'edit'])->name('general.edit');
        Route::patch('settings/general', [GeneralController::class, 'update'])->name('general.update');

        Route::get('settings/teams', [TeamMemberController::class, 'index'])->name('teams.members.index');
        Route::patch('settings/teams/members/{user}', [TeamMemberController::class, 'update'])->name('teams.members.update');
        Route::delete('settings/teams/members/{user}', [TeamMemberController::class, 'destroy'])->name('teams.members.destroy');

        Route::post('settings/teams/invitations', [TeamInvitationController::class, 'store'])->name('teams.invitations.store');
        Route::delete('settings/teams/invitations/{invitation}', [TeamInvitationController::class, 'destroy'])->name('teams.invitations.destroy');

        Route::get('settings/roles', [RoleController::class, 'index'])->name('roles.index');
        Route::post('settings/roles', [RoleController::class, 'store'])->name('roles.store');
        Route::patch('settings/roles/{role}', [RoleController::class, 'update'])->name('roles.update');
        Route::delete('settings/roles/{role}', [RoleController::class, 'destroy'])->name('roles.destroy');
    });
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
