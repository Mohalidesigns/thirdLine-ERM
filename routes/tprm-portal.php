<?php

use App\Http\Controllers\TprmPortal\AssessmentController;
use App\Http\Controllers\TprmPortal\DashboardController;
use App\Http\Controllers\TprmPortal\FindingController;
use App\Http\Controllers\TprmPortal\InvitationController;
use App\Http\Controllers\TprmPortal\MfaController;
use App\Http\Controllers\TprmPortal\SessionController;
use App\Http\Controllers\TprmPortal\TrustProfileController;
use App\Http\Controllers\TprmPortal\VendorRecordController;
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

    /*
    | Assessments — FR-PRT-03.
    |
    | `answers.save` is posted on every field blur, which is what makes
    | save-and-resume the default rather than a button somebody forgets. A
    | tier-1 questionnaire is forty questions across three people and a week;
    | a form that loses work on a dropped connection gets answered in a
    | spreadsheet and emailed instead.
    */
    Route::get('assessments', [AssessmentController::class, 'index'])->name('assessments.index');
    Route::get('assessments/{assessment}', [AssessmentController::class, 'show'])->name('assessments.show');
    Route::post('assessments/{assessment}/answers/{response}', [AssessmentController::class, 'saveAnswer'])
        ->name('assessments.answers.save');
    Route::post('assessments/{assessment}/sections/{section}/delegate', [AssessmentController::class, 'delegate'])
        ->name('assessments.delegate');
    Route::post('assessments/{assessment}/submit', [AssessmentController::class, 'submit'])
        ->name('assessments.submit');
    Route::post('assessments/{assessment}/messages', [AssessmentController::class, 'postMessage'])
        ->name('assessments.messages.store');

    /* Findings — FR-PRT-08. The vendor responds; it never closes. */
    Route::get('findings', [FindingController::class, 'index'])->name('findings.index');
    Route::get('findings/{finding}', [FindingController::class, 'show'])->name('findings.show');
    Route::post('findings/{finding}/messages', [FindingController::class, 'postMessage'])
        ->name('findings.messages.store');

    /* The reusable trust profile — FR-PRT-04. */
    Route::get('trust-profile', [TrustProfileController::class, 'show'])->name('trust-profile.show');
    Route::post('trust-profile', [TrustProfileController::class, 'saveDraft'])->name('trust-profile.save');
    Route::post('trust-profile/publish', [TrustProfileController::class, 'publish'])
        ->name('trust-profile.publish');

    Route::get('sharing', [TrustProfileController::class, 'sharing'])->name('sharing.index');
    Route::post('sharing/{share}/approve', [TrustProfileController::class, 'approveShare'])
        ->name('sharing.approve');
    Route::post('sharing/{share}/decline', [TrustProfileController::class, 'declineShare'])
        ->name('sharing.decline');
    Route::post('sharing/{share}/revoke', [TrustProfileController::class, 'revokeShare'])
        ->name('sharing.revoke');

    /*
    | What the vendor MAINTAINS rather than answers — FR-PRT-05, 06 and 07.
    |
    | `documents.upload` is the only endpoint in the product that accepts a
    | file from outside the bank's network. It goes through the same
    | FileUploadService the internal side uses, which validates against the
    | DETECTED mime rather than the client's header — a second upload path
    | would be a second place to get that wrong.
    */
    Route::get('documents', [VendorRecordController::class, 'documents'])->name('documents.index');
    Route::post('documents', [VendorRecordController::class, 'uploadDocument'])
        ->middleware('throttle:tprm-portal-upload')
        ->name('documents.upload');

    Route::get('subprocessors', [VendorRecordController::class, 'subprocessors'])->name('subprocessors.index');
    Route::post('subprocessors', [VendorRecordController::class, 'declareSubprocessor'])
        ->name('subprocessors.declare');

    Route::get('incidents', [VendorRecordController::class, 'incidents'])->name('incidents.index');
    Route::post('incidents', [VendorRecordController::class, 'reportIncident'])->name('incidents.report');

    Route::post('logout', [SessionController::class, 'destroy'])->name('logout');
});
