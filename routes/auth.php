<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\MfaSetupController;
use App\Http\Controllers\Auth\MfaVerifyController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Authentication and account self-service (migration Phase 1)
|--------------------------------------------------------------------------
|
| Required from routes/web.php, which defines the rate limiters these routes
| name. Route NAMES are unchanged from the retired AuthController era — the
| tests, Ziggy and every link depend on them; only the controllers and the
| renderer (Inertia) changed.
|
| Self-registration does not exist: users are provisioned by an administrator
| (Administration → User Management) or by the directory (SCIM).
*/

/* Public */

Route::get('login', [AuthenticatedSessionController::class, 'create'])->name('login');

// The credential-guessing surface. See the `login` limiter in web.php for
// why the key is (email, IP) with a separate, looser per-IP ceiling.
Route::post('login', [AuthenticatedSessionController::class, 'store'])->middleware('throttle:login');

Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

Route::get('forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');

// Sends mail to an address the caller names, so this is an outbound mailer
// pointed at a member of staff unless it is limited. Keyed per email.
Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])
    ->middleware('throttle:password-reset')->name('password.email');

Route::get('reset-password/{token}', [NewPasswordController::class, 'create'])->name('password.reset');

// The token is a 64-character random string held in the cache for an hour;
// the limit is here so it cannot be guessed at line rate anyway.
Route::post('reset-password', [NewPasswordController::class, 'store'])
    ->middleware('throttle:password-reset')->name('password.update');

/*
 * MFA VERIFICATION — behind features.mfa_totp.
 *
 * Reached mid sign-in, before the session is authenticated, so it is public.
 * The flow was rebuilt in migration Phase 1 (see config/features.php for what
 * changed); the flag stays so each environment turns the second factor on
 * deliberately. `throttle:mfa-verify` is applied even while the route 404s,
 * so turning the flag on cannot expose an unthrottled six-digit code check.
 */
Route::middleware('feature:mfa_totp')->group(function () {
    Route::get('mfa/verify', [MfaVerifyController::class, 'show'])->name('mfa.verify');
    Route::post('mfa/verify', [MfaVerifyController::class, 'verify'])->middleware('throttle:mfa-verify');
});

/* Authenticated, deliberately not permission-gated: enrolling in MFA must
   stay reachable for every authenticated user, or mfa_required_roles is
   unsatisfiable for anyone lacking a permission. */

Route::middleware(['auth'])->group(function () {
    Route::middleware('feature:mfa_totp')->group(function () {
        Route::get('mfa/setup', [MfaSetupController::class, 'show'])->name('mfa.setup');

        // Same six-digit check as mfa/verify, so the same limiter.
        Route::post('mfa/enable', [MfaSetupController::class, 'enable'])
            ->middleware('throttle:mfa-verify')->name('mfa.enable');
    });

    // dashboard.view is the platform's "may use this application" permission,
    // granted to every role; the profile is the one screen every user has.
    Route::middleware('permission:dashboard.view')->group(function () {
        Route::get('profile', [ProfileController::class, 'edit'])->name('profile.edit');
        Route::patch('profile', [ProfileController::class, 'update'])->name('profile.update');
        Route::put('profile/password', [PasswordController::class, 'update'])->name('profile.password');
    });
});
