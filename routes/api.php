<?php

use App\Http\Controllers\Scim\ScimGroupController;
use App\Http\Controllers\Scim\ScimUserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API routes
|--------------------------------------------------------------------------
|
| SCIM 2.0 provisioning. These are exempt from the `permission:` invariant
| that governs routes/web.php because the caller is a directory service, not a
| user: there is no role to check. Authorization is the bearer token, and the
| token also carries the tenant — see AuthenticateScim.
|
| RouteAuthorizationTest asserts that scim.auth is present on every one of
| them, so the exemption cannot be widened by accident.
|
*/

Route::prefix('scim/v2')
    ->middleware('scim.auth')
    ->group(function () {
        Route::get('Users', [ScimUserController::class, 'index']);
        Route::post('Users', [ScimUserController::class, 'store']);
        Route::get('Users/{id}', [ScimUserController::class, 'show']);
        Route::put('Users/{id}', [ScimUserController::class, 'update']);
        Route::patch('Users/{id}', [ScimUserController::class, 'patch']);
        Route::delete('Users/{id}', [ScimUserController::class, 'destroy']);

        Route::get('Groups', [ScimGroupController::class, 'index']);
        Route::get('Groups/{id}', [ScimGroupController::class, 'show']);
        Route::patch('Groups/{id}', [ScimGroupController::class, 'patch']);
    });
