<?php

use App\Http\Controllers\TprmPortal\DashboardController;
use App\Http\Controllers\TprmPortal\InvitationController;
use App\Http\Controllers\TprmPortal\MfaController;
use App\Http\Controllers\TprmPortal\SessionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Vendor portal — TPRM Phase 8, FR-PRT-01
|--------------------------------------------------------------------------
|
| Mounted at /vendor-portal under the `tprm-portal` middleware group, which is
| assembled in bootstrap/app.php rather than inherited from `web`. Nothing in
| this file may reference an internal controller, and nothing internal may
| reference a route named here.
|
| THE CLIENT IS IN THE PATH ON THE GUEST ROUTES, as an organisation uuid. One
| person at a shared service provider may hold accounts with several of our
| tenants under the same email address — the unique index is on
| (organization_id, email), deliberately — so a login form with no tenant in it
| could not tell which account it was authenticating. Asking "which client?" on
| the form would answer that question at the cost of turning the page into an
| oracle for which banks a given address does business with.
|
| Once signed in the tenant comes from the session, so the authenticated routes
| carry no prefix.
|
*/

Route::middleware('portal.guest')->group(function (): void {
    Route::get('/', [SessionController::class, 'entry'])->name('entry');

    Route::get('c/{client:uuid}', [SessionController::class, 'create'])->name('login');
    Route::post('c/{client:uuid}', [SessionController::class, 'store'])
        ->middleware('throttle:tprm-portal-login')
        ->name('login.attempt');

    Route::get('invitations/{token}', [InvitationController::class, 'show'])->name('invitation.show');
    Route::post('invitations/{token}', [InvitationController::class, 'accept'])
        ->middleware('throttle:tprm-portal-login')
        ->name('invitation.accept');
});

/*
| The half-authenticated screens. `portal.pending` requires an id parked in the
| session by the password step and nothing else — there is no guard session
| here, so a session stolen mid-sign-in buys the same challenge screen.
*/
Route::middleware('portal.pending')->group(function (): void {
    Route::get('mfa/setup', [MfaController::class, 'setup'])->name('mfa.setup');
    Route::post('mfa/enrol', [MfaController::class, 'enrol'])
        ->middleware('throttle:tprm-portal-login')
        ->name('mfa.enrol');

    Route::get('mfa/challenge', [MfaController::class, 'challenge'])->name('mfa.challenge');
    Route::post('mfa/verify', [MfaController::class, 'verify'])
        ->middleware('throttle:tprm-portal-login')
        ->name('mfa.verify');

    Route::post('mfa/resend', [MfaController::class, 'resend'])
        ->middleware('throttle:tprm-portal-login')
        ->name('mfa.resend');

    Route::post('mfa/abandon', [MfaController::class, 'abandon'])->name('mfa.abandon');
});

Route::middleware('portal.auth')->group(function (): void {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::post('logout', [SessionController::class, 'destroy'])->name('logout');
});
