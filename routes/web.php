<?php

use App\Http\Controllers\Auth\JoinInvitationController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Docs\ApiDocsController;
use App\Http\Controllers\Hackathon\FixedGatewayIngressController;
use App\Http\Controllers\Teams\TeamInvitationController;
use App\Http\Controllers\Webhooks\GatewayWebhookController;
use App\Http\Middleware\EnsureCurrentTeam;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::get('docs/api', ApiDocsController::class)->name('docs.api');
Route::get('docs/api/openapi.yaml', fn (): Response => response(
    File::get(base_path('docs/api/openapi.yaml')),
    200,
    ['Content-Type' => 'application/yaml; charset=UTF-8'],
))->name('docs.api.openapi');

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

// One inbound endpoint per gateway, resolved by driver (IMPLEMENTATION_V2
// §V2-4). This subsumes the old `/webhooks/nomba/{token}` — that URL still
// resolves here with `processor=nomba`, so tokens already pasted into Nomba's
// dashboard keep working without a second code path to maintain.
Route::post('webhooks/{processor}/{token}', GatewayWebhookController::class)
    ->name('webhooks.gateway.receive');

// Hackathon-only fixed ingress URL — delete this route and app/Hackathon/ after the demo.
Route::post(
    config('services.nomba.hackathon_ingress.path', 'ingres/qydaD5iz2W0V2bRPTaqlTJYVaiR2zLAd'),
    FixedGatewayIngressController::class,
)->name('hackathon.nomba.ingress');

require __DIR__.'/settings.php';
require __DIR__.'/developers.php';
require __DIR__.'/catalog.php';
require __DIR__.'/customers.php';
require __DIR__.'/subscriptions.php';
require __DIR__.'/discounts.php';
require __DIR__.'/invoices.php';
require __DIR__.'/billing-settings.php';
require __DIR__.'/hosted.php';
require __DIR__.'/portal.php';
