<?php

use App\Http\Controllers\Admin\ConfigurationBuilderController;
use App\Http\Controllers\Admin\ConfigurationBundleController;
use App\Http\Controllers\Admin\OrganizationSettingsController;
use App\Http\Controllers\Admin\SsoSettingsController;
use App\Http\Controllers\Admin\UserManagementController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\SsoController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\Risk\AiIntelligenceController;
use App\Http\Controllers\Risk\AiToolsController;
use App\Http\Controllers\Risk\AnalysisController;
use App\Http\Controllers\Risk\ApprovalController;
use App\Http\Controllers\Risk\CampaignController;
use App\Http\Controllers\Risk\ControlController;
use App\Http\Controllers\Risk\ControlTestController;
use App\Http\Controllers\Risk\DashboardController;
use App\Http\Controllers\Risk\DataImportController;
use App\Http\Controllers\Risk\DocumentRepositoryController;
use App\Http\Controllers\Risk\EmergingRiskController;
use App\Http\Controllers\Risk\ExportController;
use App\Http\Controllers\Risk\IssueController;
use App\Http\Controllers\Risk\KriController;
use App\Http\Controllers\Risk\LossEventController;
use App\Http\Controllers\Risk\MyTaskController;
use App\Http\Controllers\Risk\PeriodController;
use App\Http\Controllers\Risk\QuantificationController;
use App\Http\Controllers\Risk\QuestionnaireController;
use App\Http\Controllers\Risk\RcsaController;
use App\Http\Controllers\Risk\RegulatoryComplianceController;
use App\Http\Controllers\Risk\ReportController;
use App\Http\Controllers\Risk\RiskAppetiteController;
use App\Http\Controllers\Risk\RiskAssessmentController;
use App\Http\Controllers\Risk\RiskRegisterController;
use App\Http\Controllers\Risk\ScopingController;
use App\Http\Controllers\Risk\ThresholdController;
use App\Http\Controllers\Risk\TreatmentPlanController;
use App\Http\Controllers\Risk\WorkflowController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Authorization
|--------------------------------------------------------------------------
|
| Every route below carries a `permission:` guard. RouteAuthorizationTest
| enumerates the router and fails the build if one does not — the only
| exemptions are the unauthenticated auth flow and per-user account security
| (MFA enrolment), which cannot be gated on a permission without locking users
| out of the very control they are being asked to enable.
|
| The resource routes are written out verb by verb on purpose. Route::resource
| applies one middleware stack to all seven actions, which would have meant
| granting delete rights to anyone who can view.
|
*/

Route::get('/', fn () => redirect('/risk/dashboard'));

/* ---------------------------------------------------------------------- */
/*  Authentication (public) */
/* ---------------------------------------------------------------------- */

Route::get('login', [AuthController::class, 'showLogin'])->name('login');
Route::post('login', [AuthController::class, 'login']);
Route::post('logout', [AuthController::class, 'logout'])->name('logout');
Route::get('forgot-password', [AuthController::class, 'showForgotPassword'])->name('password.request');
Route::post('forgot-password', [AuthController::class, 'sendResetLink'])->name('password.email');
Route::get('reset-password/{token}', [AuthController::class, 'showResetPassword'])->name('password.reset');
Route::post('reset-password', [AuthController::class, 'resetPassword'])->name('password.update');
Route::get('mfa/verify', [AuthController::class, 'showMfaVerify'])->name('mfa.verify');
Route::post('mfa/verify', [AuthController::class, 'verifyMfa']);

/* ---------------------------------------------------------------------- */
/*  Single sign-on (public: the whole point is that the user is not yet */
/*  authenticated here) */
/* ---------------------------------------------------------------------- */

// {slug} selects the client organization, because none of this can rely on an
// authenticated user to tell us which tenant we are federating with.
Route::post('auth/sso/discover', [SsoController::class, 'discover'])->name('sso.discover');
Route::get('auth/sso/{slug}', [SsoController::class, 'redirect'])->name('sso.redirect');
Route::get('auth/sso/{slug}/callback', [SsoController::class, 'callback'])->name('sso.callback');
Route::get('auth/sso/{slug}/metadata', [SsoController::class, 'metadata'])->name('sso.metadata');

// The IdP posts the assertion from the user's browser, so there is no session
// CSRF token to present. The signed assertion is the authentication.
Route::post('auth/sso/{slug}/acs', [SsoController::class, 'acs'])
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class])
    ->name('sso.acs');

/* ---------------------------------------------------------------------- */
/*  Account self-service (authenticated, deliberately not permission-gated) */
/* ---------------------------------------------------------------------- */

Route::middleware(['auth'])->group(function () {
    // Enrolling in MFA must stay reachable for every authenticated user:
    // gating it on a permission would make mfa_required_roles unsatisfiable
    // for anyone who lacks that permission.
    Route::get('mfa/setup', [AuthController::class, 'showMfaSetup'])->name('mfa.setup');
    Route::post('mfa/enable', [AuthController::class, 'enableMfa'])->name('mfa.enable');

    Route::middleware('permission:notification.view')->group(function () {
        Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
        Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
        Route::get('notifications/{id}/read', [NotificationController::class, 'read'])->name('notifications.read');
    });
});

/* ---------------------------------------------------------------------- */
/*  Admin */
/* ---------------------------------------------------------------------- */

Route::prefix('admin')->middleware(['auth'])->group(function () {
    Route::middleware('permission:admin.users')->group(function () {
        Route::get('users', [UserManagementController::class, 'index'])->name('admin.users.index');
        Route::get('users/create', [UserManagementController::class, 'create'])->name('admin.users.create');
        Route::post('users', [UserManagementController::class, 'store'])->name('admin.users.store');
        Route::get('users/{user}', [UserManagementController::class, 'show'])->name('admin.users.show');
        Route::get('users/{user}/edit', [UserManagementController::class, 'edit'])->name('admin.users.edit');
        Route::match(['put', 'patch'], 'users/{user}', [UserManagementController::class, 'update'])->name('admin.users.update');
        Route::delete('users/{user}', [UserManagementController::class, 'destroy'])->name('admin.users.destroy');
        Route::patch('users/{user}/toggle-active', [UserManagementController::class, 'toggleActive'])->name('admin.users.toggle-active');
        Route::post('users/{user}/reset-password', [UserManagementController::class, 'resetPassword'])->name('admin.users.reset-password');
    });

    Route::middleware('permission:admin.settings')->group(function () {
        Route::get('settings', [OrganizationSettingsController::class, 'index'])->name('admin.settings');
        Route::put('settings/profile', [OrganizationSettingsController::class, 'updateProfile'])->name('admin.settings.profile');
        Route::put('settings/thresholds', [OrganizationSettingsController::class, 'updateThresholds'])->name('admin.settings.thresholds');
        Route::put('settings/risk', [OrganizationSettingsController::class, 'updateRiskSettings'])->name('admin.settings.risk');
        Route::put('settings/notifications', [OrganizationSettingsController::class, 'updateNotificationPreferences'])->name('admin.settings.notifications');
    });

    // Single sign-on: the client configures their own identity provider here.
    Route::middleware('permission:admin.sso')->group(function () {
        Route::get('settings/sso', [SsoSettingsController::class, 'edit'])->name('admin.settings.sso');
        Route::put('settings/sso', [SsoSettingsController::class, 'update'])->name('admin.settings.sso.update');
    });

    /* ------------------------------------------------------------------ */
    /*  WP-05 — the configuration builder */
    /* ------------------------------------------------------------------ */

    // Object types, their fields, their edges and their state machines.
    // Behind its own permission rather than admin.settings: these change the
    // shape of every record in the tenant, not a preference.
    Route::middleware('permission:admin.metadata')->group(function () {
        Route::get('builder', [ConfigurationBuilderController::class, 'index'])->name('admin.builder');
        Route::get('builder/object-types', [ConfigurationBuilderController::class, 'objectTypes'])->name('admin.builder.object-types');
        Route::get('builder/object-types/{objectType}/attributes', [ConfigurationBuilderController::class, 'attributes'])->name('admin.builder.attributes');
        Route::get('builder/relationship-types', [ConfigurationBuilderController::class, 'relationshipTypes'])->name('admin.builder.relationship-types');
        Route::get('builder/lifecycles', [ConfigurationBuilderController::class, 'lifecycles'])->name('admin.builder.lifecycles');
    });

    // Redefining what Critical means re-rates the whole register, so it is
    // grantable separately from the rest of the builder.
    Route::middleware('permission:admin.scoring')->group(function () {
        Route::get('builder/scoring-profiles', [ConfigurationBuilderController::class, 'scoringProfiles'])->name('admin.builder.scoring-profiles');
    });

    // Configuration bundles: export, diff, import, rollback. The narrowest
    // grant in the product — an import rewrites the tenant's definition set.
    Route::middleware('permission:admin.configuration')->group(function () {
        Route::get('configuration', [ConfigurationBundleController::class, 'index'])->name('admin.configuration');
        Route::post('configuration/export', [ConfigurationBundleController::class, 'export'])->name('admin.configuration.export');
        Route::get('configuration/{bundle}/download', [ConfigurationBundleController::class, 'download'])->name('admin.configuration.download');
        Route::post('configuration/diff', [ConfigurationBundleController::class, 'diff'])->name('admin.configuration.diff');
        Route::post('configuration/{bundle}/apply', [ConfigurationBundleController::class, 'apply'])->name('admin.configuration.apply');
        Route::post('configuration/applications/{application}/rollback', [ConfigurationBundleController::class, 'rollback'])->name('admin.configuration.rollback');
    });
});

Route::prefix('risk')->middleware(['auth'])->group(function () {
    // Dashboard
    Route::get('/dashboard', [DashboardController::class, 'index'])
        ->middleware('permission:dashboard.view')->name('risk.dashboard');

    /* ------------------------------------------------------------------ */
    /*  Scoping / Entity Management */
    /* ------------------------------------------------------------------ */
    Route::get('scoping/dashboard', [ScopingController::class, 'dashboard'])
        ->middleware('permission:entity.view')->name('risk.scoping.dashboard');
    Route::get('scoping', [ScopingController::class, 'index'])
        ->middleware('permission:entity.view')->name('risk.scoping.index');
    Route::get('scoping/create', [ScopingController::class, 'create'])
        ->middleware('permission:entity.create')->name('risk.scoping.create');
    Route::post('scoping', [ScopingController::class, 'store'])
        ->middleware('permission:entity.create')->name('risk.scoping.store');
    Route::get('scoping/{scoping}', [ScopingController::class, 'show'])
        ->middleware('permission:entity.view')->name('risk.scoping.show');
    Route::get('scoping/{scoping}/edit', [ScopingController::class, 'edit'])
        ->middleware('permission:entity.edit')->name('risk.scoping.edit');
    Route::match(['put', 'patch'], 'scoping/{scoping}', [ScopingController::class, 'update'])
        ->middleware('permission:entity.edit')->name('risk.scoping.update');
    Route::delete('scoping/{scoping}', [ScopingController::class, 'destroy'])
        ->middleware('permission:entity.delete')->name('risk.scoping.destroy');

    /* ------------------------------------------------------------------ */
    /*  Risk Register */
    /* ------------------------------------------------------------------ */
    Route::post('register/{register}/map-control', [RiskRegisterController::class, 'mapControl'])
        ->middleware('permission:risk.edit')->name('risk.register.map-control');
    Route::get('register', [RiskRegisterController::class, 'index'])
        ->middleware('permission:risk.view')->name('risk.register.index');
    Route::get('register/create', [RiskRegisterController::class, 'create'])
        ->middleware('permission:risk.create')->name('risk.register.create');
    Route::post('register', [RiskRegisterController::class, 'store'])
        ->middleware('permission:risk.create')->name('risk.register.store');
    Route::get('register/{register}', [RiskRegisterController::class, 'show'])
        ->middleware('permission:risk.view')->name('risk.register.show');
    Route::get('register/{register}/edit', [RiskRegisterController::class, 'edit'])
        ->middleware('permission:risk.edit')->name('risk.register.edit');
    Route::match(['put', 'patch'], 'register/{register}', [RiskRegisterController::class, 'update'])
        ->middleware('permission:risk.edit')->name('risk.register.update');
    Route::delete('register/{register}', [RiskRegisterController::class, 'destroy'])
        ->middleware('permission:risk.delete')->name('risk.register.destroy');

    /* ------------------------------------------------------------------ */
    /*  Risk Assessments */
    /* ------------------------------------------------------------------ */
    Route::get('assessments', [RiskAssessmentController::class, 'index'])
        ->middleware('permission:assessment.view')->name('risk.assessments.index');
    Route::get('assessments/create', [RiskAssessmentController::class, 'create'])
        ->middleware('permission:assessment.create')->name('risk.assessments.create');
    Route::post('assessments', [RiskAssessmentController::class, 'store'])
        ->middleware('permission:assessment.create')->name('risk.assessments.store');
    Route::get('assessments/{assessment}', [RiskAssessmentController::class, 'show'])
        ->middleware('permission:assessment.view')->name('risk.assessments.show');
    Route::put('assessments/{assessment}', [RiskAssessmentController::class, 'update'])
        ->middleware('permission:assessment.create')->name('risk.assessments.update');
    Route::post('assessments/{assessment}/submit', [RiskAssessmentController::class, 'submit'])
        ->middleware('permission:assessment.submit')->name('risk.assessments.submit');
    Route::post('assessments/{assessment}/approve', [RiskAssessmentController::class, 'approve'])
        ->middleware('permission:assessment.approve')->name('risk.assessments.approve');
    Route::post('assessments/{assessment}/reject', [RiskAssessmentController::class, 'reject'])
        ->middleware('permission:assessment.reject')->name('risk.assessments.reject');
    Route::post('assessments/{assessment}/resubmit', [RiskAssessmentController::class, 'resubmit'])
        ->middleware('permission:assessment.submit')->name('risk.assessments.resubmit');

    /* ------------------------------------------------------------------ */
    /*  Controls */
    /* ------------------------------------------------------------------ */
    Route::get('controls', [ControlController::class, 'index'])
        ->middleware('permission:control.view')->name('risk.controls.index');
    Route::get('controls/create', [ControlController::class, 'create'])
        ->middleware('permission:control.create')->name('risk.controls.create');
    Route::post('controls', [ControlController::class, 'store'])
        ->middleware('permission:control.create')->name('risk.controls.store');
    Route::get('controls/{control}', [ControlController::class, 'show'])
        ->middleware('permission:control.view')->name('risk.controls.show');
    Route::get('controls/{control}/edit', [ControlController::class, 'edit'])
        ->middleware('permission:control.edit')->name('risk.controls.edit');
    Route::match(['put', 'patch'], 'controls/{control}', [ControlController::class, 'update'])
        ->middleware('permission:control.edit')->name('risk.controls.update');
    Route::delete('controls/{control}', [ControlController::class, 'destroy'])
        ->middleware('permission:control.delete')->name('risk.controls.destroy');
    Route::post('controls/{control}/link-risk', [ControlController::class, 'linkToRisk'])
        ->middleware('permission:control.edit')->name('risk.controls.link-risk');
    Route::delete('controls/{control}/unlink-risk/{risk}', [ControlController::class, 'unlinkFromRisk'])
        ->middleware('permission:control.edit')->name('risk.controls.unlink-risk');

    /* ------------------------------------------------------------------ */
    /*  Treatment Plans */
    /* ------------------------------------------------------------------ */
    Route::get('treatments/dashboard', [TreatmentPlanController::class, 'dashboard'])
        ->middleware('permission:treatment.view')->name('risk.treatments.dashboard');
    Route::get('treatments/review', [TreatmentPlanController::class, 'review'])
        ->middleware('permission:treatment.approve')->name('risk.treatments.review');
    Route::get('treatments', [TreatmentPlanController::class, 'index'])
        ->middleware('permission:treatment.view')->name('risk.treatments.index');
    Route::get('treatments/create', [TreatmentPlanController::class, 'create'])
        ->middleware('permission:treatment.create')->name('risk.treatments.create');
    Route::post('treatments', [TreatmentPlanController::class, 'store'])
        ->middleware('permission:treatment.create')->name('risk.treatments.store');
    Route::get('treatments/{treatment}', [TreatmentPlanController::class, 'show'])
        ->middleware('permission:treatment.view')->name('risk.treatments.show');
    Route::get('treatments/{treatment}/edit', [TreatmentPlanController::class, 'edit'])
        ->middleware('permission:treatment.edit')->name('risk.treatments.edit');
    Route::match(['put', 'patch'], 'treatments/{treatment}', [TreatmentPlanController::class, 'update'])
        ->middleware('permission:treatment.edit')->name('risk.treatments.update');
    Route::delete('treatments/{treatment}', [TreatmentPlanController::class, 'destroy'])
        ->middleware('permission:treatment.delete')->name('risk.treatments.destroy');
    Route::post('treatments/{treatment}/submit', [TreatmentPlanController::class, 'submitForReview'])
        ->middleware('permission:treatment.create')->name('risk.treatments.submit');
    Route::post('treatments/{treatment}/approve', [TreatmentPlanController::class, 'approve'])
        ->middleware('permission:treatment.approve')->name('risk.treatments.approve');
    Route::post('treatments/{treatment}/reject', [TreatmentPlanController::class, 'reject'])
        ->middleware('permission:treatment.approve')->name('risk.treatments.reject');
    Route::post('treatments/{treatment}/resubmit', [TreatmentPlanController::class, 'resubmit'])
        ->middleware('permission:treatment.create')->name('risk.treatments.resubmit');
    Route::post('treatments/{treatment}/comment', [TreatmentPlanController::class, 'comment'])
        ->middleware('permission:treatment.view')->name('risk.treatments.comment');

    /* ------------------------------------------------------------------ */
    /*  Reporting periods (WP-04) */
    /* ------------------------------------------------------------------ */
    // Changing the selected period is a read operation on every screen the
    // user can already see, so it is gated on the same baseline permission
    // that admits a user to the application at all.
    Route::get('periods/select', [PeriodController::class, 'select'])
        ->middleware('permission:dashboard.view')->name('risk.periods.select');
    Route::get('periods', [PeriodController::class, 'index'])
        ->middleware('permission:period.view')->name('risk.periods.index');
    Route::post('periods/{period}/close', [PeriodController::class, 'close'])
        ->middleware('permission:period.close')->name('risk.periods.close');
    Route::post('periods/{period}/reopen', [PeriodController::class, 'reopen'])
        ->middleware('permission:period.reopen')->name('risk.periods.reopen');

    /* ------------------------------------------------------------------ */
    /*  Threshold re-baselining (WP-04) */
    /* ------------------------------------------------------------------ */
    Route::get('thresholds/rebaseline', [ThresholdController::class, 'index'])
        ->middleware('permission:threshold.view')->name('risk.thresholds.rebaseline');
    Route::post('thresholds/rebaseline/{approval}/approve', [ThresholdController::class, 'approve'])
        ->middleware('permission:threshold.rebaseline_approve')->name('risk.thresholds.rebaseline.approve');
    Route::post('thresholds/rebaseline/{approval}/reject', [ThresholdController::class, 'reject'])
        ->middleware('permission:threshold.rebaseline_approve')->name('risk.thresholds.rebaseline.reject');

    /* ------------------------------------------------------------------ */
    /*  KRI Monitoring */
    /* ------------------------------------------------------------------ */
    Route::get('kri/dashboard', [KriController::class, 'dashboard'])
        ->middleware('permission:kri.view')->name('risk.kri.dashboard');
    Route::get('kri/thresholds', [KriController::class, 'thresholds'])
        ->middleware('permission:kri.view')->name('risk.kri.thresholds');
    Route::put('kri/thresholds', [KriController::class, 'updateThresholds'])
        ->middleware('permission:kri.edit')->name('risk.kri.thresholds.update');
    Route::get('kri/breaches', [KriController::class, 'breaches'])
        ->middleware('permission:kri.view')->name('risk.kri.breaches');
    // The breach lifecycle. Acknowledgement is what makes MTTR computable, so
    // it carries its own permission rather than riding on kri.edit.
    Route::post('kri/breaches/{breach}/acknowledge', [KriController::class, 'acknowledgeBreach'])
        ->middleware('permission:kri.acknowledge_breach')->name('risk.kri.breaches.acknowledge');
    Route::post('kri/breaches/{breach}/resolve', [KriController::class, 'resolveBreach'])
        ->middleware('permission:kri.acknowledge_breach')->name('risk.kri.breaches.resolve');
    Route::post('kri/{kri}/measurement', [KriController::class, 'recordMeasurement'])
        ->middleware('permission:kri.record_measurement')->name('risk.kri.record-measurement');
    Route::get('kri', [KriController::class, 'index'])
        ->middleware('permission:kri.view')->name('risk.kri.index');
    Route::get('kri/create', [KriController::class, 'create'])
        ->middleware('permission:kri.create')->name('risk.kri.create');
    Route::post('kri', [KriController::class, 'store'])
        ->middleware('permission:kri.create')->name('risk.kri.store');
    Route::get('kri/{kri}', [KriController::class, 'show'])
        ->middleware('permission:kri.view')->name('risk.kri.show');
    Route::get('kri/{kri}/edit', [KriController::class, 'edit'])
        ->middleware('permission:kri.edit')->name('risk.kri.edit');
    Route::match(['put', 'patch'], 'kri/{kri}', [KriController::class, 'update'])
        ->middleware('permission:kri.edit')->name('risk.kri.update');
    Route::delete('kri/{kri}', [KriController::class, 'destroy'])
        ->middleware('permission:kri.delete')->name('risk.kri.destroy');

    /* ------------------------------------------------------------------ */
    /*  Risk Appetite */
    /* ------------------------------------------------------------------ */
    Route::get('appetite', [RiskAppetiteController::class, 'index'])
        ->middleware('permission:appetite.view')->name('risk.appetite.index');
    Route::post('appetite', [RiskAppetiteController::class, 'store'])
        ->middleware('permission:appetite.manage')->name('risk.appetite.store');
    Route::put('appetite/{appetite}', [RiskAppetiteController::class, 'update'])
        ->middleware('permission:appetite.manage')->name('risk.appetite.update');

    /* ------------------------------------------------------------------ */
    /*  Loss Events */
    /* ------------------------------------------------------------------ */
    Route::get('loss-events/dashboard', [LossEventController::class, 'dashboard'])
        ->middleware('permission:loss_event.view')->name('risk.loss-events.dashboard');
    Route::get('loss-events/near-misses', [LossEventController::class, 'nearMisses'])
        ->middleware('permission:loss_event.view')->name('risk.loss-events.near-misses');
    Route::post('loss-events/near-misses', [LossEventController::class, 'storeNearMiss'])
        ->middleware('permission:loss_event.create')->name('risk.loss-events.store-near-miss');
    Route::get('loss-events/near-misses/create', [LossEventController::class, 'createNearMiss'])
        ->middleware('permission:loss_event.create')->name('risk.loss-events.create-near-miss');
    Route::post('loss-events/convert-near-miss/{nearMiss}', [LossEventController::class, 'convertNearMiss'])
        ->middleware('permission:loss_event.create')->name('risk.loss-events.convert-near-miss');
    Route::get('loss-events/approvals', [LossEventController::class, 'approvals'])
        ->middleware('permission:loss_event.approve')->name('risk.loss-events.approvals');
    Route::get('loss-events/rca', [LossEventController::class, 'rcaIndex'])
        ->middleware('permission:loss_event.view')->name('risk.loss-events.rca');
    Route::get('loss-events/reports', [LossEventController::class, 'reports'])
        ->middleware('permission:loss_event.view')->name('risk.loss-events.reports');

    // Regulatory and management extracts (ExportController) — declared before
    // the {lossEvent} routes so the literal "reports" segment wins.
    Route::match(['get', 'post'], 'loss-events/reports/cbn-orms', [ExportController::class, 'lossEventsCbnOrms'])
        ->middleware('permission:report.export')->name('risk.export.loss-events.cbn-orms');
    Route::match(['get', 'post'], 'loss-events/reports/basel', [ExportController::class, 'lossEventsBasel'])
        ->middleware('permission:report.export')->name('risk.export.loss-events.basel');
    Route::match(['get', 'post'], 'loss-events/reports/management', [ExportController::class, 'lossEventsManagement'])
        ->middleware('permission:report.export')->name('risk.export.loss-events.management');
    Route::match(['get', 'post'], 'loss-events/reports/nfiu', [ExportController::class, 'lossEventsNfiu'])
        ->middleware('permission:report.export')->name('risk.export.loss-events.nfiu');
    Route::match(['get', 'post'], 'loss-events/reports/trends', [ExportController::class, 'lossEventsTrends'])
        ->middleware('permission:report.export')->name('risk.export.loss-events.trends');
    Route::match(['get', 'post'], 'loss-events/reports/export', [ExportController::class, 'lossEventsFullExport'])
        ->middleware('permission:report.export')->name('risk.export.loss-events.full');

    Route::get('loss-events/{lossEvent}/rca', [LossEventController::class, 'rca'])
        ->middleware('permission:loss_event.view')->name('risk.loss-events.show-rca');
    Route::post('loss-events/{lossEvent}/rca', [LossEventController::class, 'storeRca'])
        ->middleware('permission:loss_event.edit')->name('risk.loss-events.store-rca');
    Route::post('loss-events/{lossEvent}/rca/approve', [LossEventController::class, 'approveRca'])
        ->middleware('permission:loss_event.approve')->name('risk.loss-events.approve-rca');
    Route::post('loss-events/{lossEvent}/attachments', [LossEventController::class, 'uploadAttachment'])
        ->middleware('permission:loss_event.edit')->name('risk.loss-events.upload-attachment');
    Route::get('loss-events/{lossEvent}/attachments/{attachment}/download', [LossEventController::class, 'downloadAttachment'])
        ->middleware('permission:loss_event.view')->name('risk.loss-events.download-attachment');
    Route::delete('loss-events/{lossEvent}/attachments/{attachment}', [LossEventController::class, 'deleteAttachment'])
        ->middleware('permission:loss_event.delete')->name('risk.loss-events.delete-attachment');
    Route::patch('loss-events/{lossEvent}/status', [LossEventController::class, 'updateStatus'])
        ->middleware('permission:loss_event.edit')->name('risk.loss-events.update-status');
    Route::post('loss-events/{lossEvent}/approval', [LossEventController::class, 'submitApproval'])
        ->middleware('permission:loss_event.approve')->name('risk.loss-events.submit-approval');

    Route::get('loss-events', [LossEventController::class, 'index'])
        ->middleware('permission:loss_event.view')->name('risk.loss-events.index');
    Route::get('loss-events/create', [LossEventController::class, 'create'])
        ->middleware('permission:loss_event.create')->name('risk.loss-events.create');
    Route::post('loss-events', [LossEventController::class, 'store'])
        ->middleware('permission:loss_event.create')->name('risk.loss-events.store');
    Route::get('loss-events/{loss_event}', [LossEventController::class, 'show'])
        ->middleware('permission:loss_event.view')->name('risk.loss-events.show');
    Route::get('loss-events/{loss_event}/edit', [LossEventController::class, 'edit'])
        ->middleware('permission:loss_event.edit')->name('risk.loss-events.edit');
    Route::match(['put', 'patch'], 'loss-events/{loss_event}', [LossEventController::class, 'update'])
        ->middleware('permission:loss_event.edit')->name('risk.loss-events.update');
    Route::delete('loss-events/{loss_event}', [LossEventController::class, 'destroy'])
        ->middleware('permission:loss_event.delete')->name('risk.loss-events.destroy');

    /* ------------------------------------------------------------------ */
    /*  Issues */
    /* ------------------------------------------------------------------ */
    Route::get('issues/dashboard', [IssueController::class, 'dashboard'])
        ->middleware('permission:issue.view')->name('risk.issues.dashboard');
    Route::get('issues/ageing', [IssueController::class, 'ageingReport'])
        ->middleware('permission:issue.view')->name('risk.issues.ageing');
    Route::get('issues/closure', [IssueController::class, 'closureList'])
        ->middleware('permission:issue.close')->name('risk.issues.closure');
    Route::patch('issues/{issue}/status', [IssueController::class, 'updateStatus'])
        ->middleware('permission:issue.edit')->name('risk.issues.update-status');
    Route::post('issues/{issue}/remediation-actions', [IssueController::class, 'addRemediationAction'])
        ->middleware('permission:issue.edit')->name('risk.issues.add-action');
    Route::patch('issues/{issue}/remediation-actions/{action}/complete', [IssueController::class, 'completeAction'])
        ->middleware('permission:issue.edit')->name('risk.issues.complete-action');
    Route::post('issues/{issue}/progress-updates', [IssueController::class, 'addProgressUpdate'])
        ->middleware('permission:issue.edit')->name('risk.issues.add-update');
    Route::post('issues/{issue}/request-closure', [IssueController::class, 'requestClosure'])
        ->middleware('permission:issue.edit')->name('risk.issues.request-closure');
    Route::post('issues/{issue}/approve-closure', [IssueController::class, 'approveClosure'])
        ->middleware('permission:issue.close')->name('risk.issues.approve-closure');
    Route::post('issues/{issue}/reject-closure', [IssueController::class, 'rejectClosure'])
        ->middleware('permission:issue.close')->name('risk.issues.reject-closure');
    Route::get('issues/{issue}/attachments/{attachment}/download', [IssueController::class, 'downloadAttachment'])
        ->middleware('permission:issue.view')->name('risk.issues.download-attachment');
    Route::post('issues/{issue}/attachments', [IssueController::class, 'uploadAttachment'])
        ->middleware('permission:issue.edit')->name('risk.issues.upload-attachment');

    Route::get('issues', [IssueController::class, 'index'])
        ->middleware('permission:issue.view')->name('risk.issues.index');
    Route::get('issues/create', [IssueController::class, 'create'])
        ->middleware('permission:issue.create')->name('risk.issues.create');
    Route::post('issues', [IssueController::class, 'store'])
        ->middleware('permission:issue.create')->name('risk.issues.store');
    Route::get('issues/{issue}', [IssueController::class, 'show'])
        ->middleware('permission:issue.view')->name('risk.issues.show');
    Route::get('issues/{issue}/edit', [IssueController::class, 'edit'])
        ->middleware('permission:issue.edit')->name('risk.issues.edit');
    Route::match(['put', 'patch'], 'issues/{issue}', [IssueController::class, 'update'])
        ->middleware('permission:issue.edit')->name('risk.issues.update');
    Route::delete('issues/{issue}', [IssueController::class, 'destroy'])
        ->middleware('permission:issue.delete')->name('risk.issues.destroy');

    /* ------------------------------------------------------------------ */
    /*  Unified document / evidence repository */
    /* ------------------------------------------------------------------ */
    Route::get('documents', [DocumentRepositoryController::class, 'index'])
        ->middleware('permission:document.view')->name('risk.documents.index');

    /* ------------------------------------------------------------------ */
    /*  Quantification */
    /* ------------------------------------------------------------------ */
    Route::get('quantification/dashboard', [QuantificationController::class, 'dashboard'])
        ->middleware('permission:quantification.view')->name('risk.quantification.dashboard');
    Route::get('quantification/scenarios', [QuantificationController::class, 'scenarios'])
        ->middleware('permission:quantification.view')->name('risk.quantification.scenarios');
    Route::get('quantification/scenarios/create', [QuantificationController::class, 'createScenario'])
        ->middleware('permission:quantification.create')->name('risk.quantification.create-scenario');
    Route::post('quantification/scenarios', [QuantificationController::class, 'storeScenario'])
        ->middleware('permission:quantification.create')->name('risk.quantification.store-scenario');
    Route::get('quantification/scenarios/{scenario}', [QuantificationController::class, 'showScenario'])
        ->middleware('permission:quantification.view')->name('risk.quantification.show-scenario');
    Route::get('quantification/scenarios/{scenario}/edit', [QuantificationController::class, 'editScenario'])
        ->middleware('permission:quantification.create')->name('risk.quantification.edit-scenario');
    Route::put('quantification/scenarios/{scenario}', [QuantificationController::class, 'updateScenario'])
        ->middleware('permission:quantification.create')->name('risk.quantification.update-scenario');
    Route::get('quantification/simulate', [QuantificationController::class, 'simulate'])
        ->middleware('permission:quantification.view')->name('risk.quantification.simulate');
    Route::post('quantification/simulate', [QuantificationController::class, 'runSimulation'])
        ->middleware('permission:quantification.run_simulation')->name('risk.quantification.run-simulation');
    Route::get('quantification/results', [QuantificationController::class, 'results'])
        ->middleware('permission:quantification.view')->name('risk.quantification.results');
    Route::get('quantification/results/{simulation}', [QuantificationController::class, 'showResults'])
        ->middleware('permission:quantification.view')->name('risk.quantification.show-results');
    Route::get('quantification/icaap', [QuantificationController::class, 'icaap'])
        ->middleware('permission:quantification.view')->name('risk.quantification.icaap');
    Route::get('quantification/library', [QuantificationController::class, 'library'])
        ->middleware('permission:quantification.view')->name('risk.quantification.library');
    Route::post('quantification/library/import/{libraryId}', [QuantificationController::class, 'importLibrary'])
        ->middleware('permission:quantification.create')->name('risk.quantification.library.import');
    Route::get('quantification/settings', [QuantificationController::class, 'settings'])
        ->middleware('permission:quantification.view')->name('risk.quantification.settings');
    Route::put('quantification/settings', [QuantificationController::class, 'updateSettings'])
        ->middleware('permission:quantification.create')->name('risk.quantification.update-settings');
    Route::get('quantification/reports', [QuantificationController::class, 'reports'])
        ->middleware('permission:quantification.view')->name('risk.quantification.reports');
    Route::get('quantification/reports/capital-adequacy', [QuantificationController::class, 'capitalAdequacyReport'])
        ->middleware('permission:quantification.view')->name('risk.quantification.reports.capital-adequacy');
    Route::get('quantification/reports/stress-testing', [QuantificationController::class, 'stressTestingReport'])
        ->middleware('permission:quantification.view')->name('risk.quantification.reports.stress-testing');
    Route::get('quantification/reports/risk-contribution', [QuantificationController::class, 'riskContributionReport'])
        ->middleware('permission:quantification.view')->name('risk.quantification.reports.risk-contribution');
    Route::get('quantification/reports/regulatory-pack', [QuantificationController::class, 'regulatoryPack'])
        ->middleware('permission:quantification.view')->name('risk.quantification.reports.regulatory-pack');

    /* ------------------------------------------------------------------ */
    /*  RCSA */
    /* ------------------------------------------------------------------ */
    Route::get('rcsa/dashboard', [RcsaController::class, 'dashboard'])
        ->middleware('permission:rcsa.view')->name('risk.rcsa.dashboard');
    Route::get('rcsa/worksheet', [RcsaController::class, 'worksheet'])
        ->middleware('permission:rcsa.view')->name('risk.rcsa.worksheet');
    Route::post('rcsa/worksheet', [RcsaController::class, 'storeWorksheet'])
        ->middleware('permission:rcsa.submit')->name('risk.rcsa.worksheet.store');
    Route::get('rcsa/controls', [RcsaController::class, 'controls'])
        ->middleware('permission:rcsa.view')->name('risk.rcsa.controls');
    Route::get('rcsa/matrix', [RcsaController::class, 'matrix'])
        ->middleware('permission:rcsa.view')->name('risk.rcsa.matrix');

    /* ------------------------------------------------------------------ */
    /*  Analysis */
    /* ------------------------------------------------------------------ */
    Route::middleware('permission:analysis.view')->group(function () {
        Route::get('analysis/heatmap', [AnalysisController::class, 'heatmap'])->name('risk.analysis.heatmap');
        Route::get('analysis/bowtie', [AnalysisController::class, 'bowtie'])->name('risk.analysis.bowtie');
        Route::get('analysis/trends', [AnalysisController::class, 'trends'])->name('risk.analysis.trends');
        Route::get('analysis/correlation', [AnalysisController::class, 'correlation'])->name('risk.analysis.correlation');
    });

    /* ------------------------------------------------------------------ */
    /*  Reports */
    /* ------------------------------------------------------------------ */
    Route::get('reports/executive', [ReportController::class, 'executive'])
        ->middleware('permission:report.view')->name('risk.reports.executive');
    Route::get('reports/board', [ReportController::class, 'board'])
        ->middleware('permission:report.view')->name('risk.reports.board');
    Route::get('reports/regulatory', [ReportController::class, 'regulatory'])
        ->middleware('permission:report.view')->name('risk.reports.regulatory');
    Route::get('reports/custom', [ReportController::class, 'custom'])
        ->middleware('permission:report.view')->name('risk.reports.custom');
    Route::match(['get', 'post'], 'reports/custom/generate', [ReportController::class, 'generateCustom'])
        ->middleware('permission:report.generate')->name('risk.reports.custom.generate');

    // Queued document generation and the stored-artifact download.
    Route::get('reports/library', [ReportController::class, 'library'])
        ->middleware('permission:report.view')->name('risk.reports.library');
    Route::post('reports/queue', [ReportController::class, 'queue'])
        ->middleware('permission:report.generate')->name('risk.reports.queue');
    Route::get('reports/{report}/status', [ReportController::class, 'status'])
        ->middleware('permission:report.view')->name('risk.reports.status');
    Route::get('reports/{report}/status.json', [ReportController::class, 'statusJson'])
        ->middleware('permission:report.view')->name('risk.reports.status-json');
    Route::get('reports/{report}/download', [ReportController::class, 'download'])
        ->middleware('permission:report.view')->name('risk.reports.download');

    // Board pack section configuration — order and inclusion, per organization.
    Route::get('reports/board-pack/sections', [ReportController::class, 'boardPackSections'])
        ->middleware('permission:report.view')->name('risk.reports.board-pack.sections');
    Route::put('reports/board-pack/sections', [ReportController::class, 'updateBoardPackSections'])
        ->middleware('permission:report.generate')->name('risk.reports.board-pack.sections.update');

    /* ------------------------------------------------------------------ */
    /*  AI Intelligence */
    /* ------------------------------------------------------------------ */
    // The three intelligence screens are gated by config('features.ai_intelligence')
    // in ADDITION to the permission check: they 404 outright unless the
    // environment has explicitly opted in. AI tools health is not gated —
    // it reports on the LLM connection, which is a separate concern.
    Route::middleware(['permission:ai.view', 'feature:ai_intelligence'])->group(function () {
        Route::get('ai/predictive', [AiIntelligenceController::class, 'predictive'])->name('risk.ai.predictive');
        Route::get('ai/radar', [AiIntelligenceController::class, 'radar'])->name('risk.ai.radar');
        Route::get('ai/regulatory-pulse', [AiIntelligenceController::class, 'regulatoryPulse'])->name('risk.ai.regulatory-pulse');
    });

    Route::middleware('permission:ai.view')->group(function () {
        Route::get('ai/tools/health', [AiToolsController::class, 'health'])->name('risk.ai.tools.health');
    });

    /* ------------------------------------------------------------------ */
    /*  Emerging risk register (what the Risk Radar plots) */
    /* ------------------------------------------------------------------ */
    // Not feature-gated: the register is a plain register, useful with or
    // without the radar screen in front of it. It reuses the risk.* permission
    // set — an emerging risk is a register object.
    Route::get('emerging-risks', [EmergingRiskController::class, 'index'])
        ->middleware('permission:risk.view')->name('risk.emerging.index');
    Route::get('emerging-risks/create', [EmergingRiskController::class, 'create'])
        ->middleware('permission:risk.create')->name('risk.emerging.create');
    Route::post('emerging-risks', [EmergingRiskController::class, 'store'])
        ->middleware('permission:risk.create')->name('risk.emerging.store');
    Route::get('emerging-risks/{emerging}/edit', [EmergingRiskController::class, 'edit'])
        ->middleware('permission:risk.edit')->name('risk.emerging.edit');
    Route::put('emerging-risks/{emerging}', [EmergingRiskController::class, 'update'])
        ->middleware('permission:risk.edit')->name('risk.emerging.update');
    Route::post('emerging-risks/{emerging}/review', [EmergingRiskController::class, 'review'])
        ->middleware('permission:risk.edit')->name('risk.emerging.review');
    Route::delete('emerging-risks/{emerging}', [EmergingRiskController::class, 'destroy'])
        ->middleware('permission:risk.delete')->name('risk.emerging.destroy');

    // Live LLM calls: separate permission, because these spend tokens and can
    // echo record content back to the caller.
    Route::middleware('permission:ai.use')->group(function () {
        Route::post('ai/tools/risk-statement', [AiToolsController::class, 'riskStatement'])->name('risk.ai.tools.risk-statement');
        Route::post('ai/tools/control-recommendations', [AiToolsController::class, 'controlRecommendations'])->name('risk.ai.tools.control-recommendations');
        Route::post('ai/tools/kri-suggestions', [AiToolsController::class, 'kriSuggestions'])->name('risk.ai.tools.kri-suggestions');
        Route::post('ai/tools/control-description', [AiToolsController::class, 'controlDescription'])->name('risk.ai.tools.control-description');
        Route::post('ai/tools/treatment-description', [AiToolsController::class, 'treatmentDescription'])->name('risk.ai.tools.treatment-description');
        Route::post('ai/tools/kri-description', [AiToolsController::class, 'kriDescription'])->name('risk.ai.tools.kri-description');
        Route::post('ai/tools/executive-narrative', [AiToolsController::class, 'executiveNarrative'])->name('risk.ai.tools.executive-narrative');
    });

    /* ------------------------------------------------------------------ */
    /*  Exports (CSV downloads) */
    /* ------------------------------------------------------------------ */
    Route::middleware('permission:report.export')->group(function () {
        Route::get('export/dashboard', [ExportController::class, 'dashboard'])->name('risk.export.dashboard');
        Route::get('export/register', [ExportController::class, 'risks'])->name('risk.export.register');
        Route::get('export/assessments', [ExportController::class, 'assessments'])->name('risk.export.assessments');
        Route::get('export/controls', [ExportController::class, 'controls'])->name('risk.export.controls');
        Route::get('export/appetite', [ExportController::class, 'appetite'])->name('risk.export.appetite');
        Route::get('export/rcsa-matrix', [ExportController::class, 'rcsaMatrix'])->name('risk.export.rcsa-matrix');
        Route::get('export/issues', [ExportController::class, 'issues'])->name('risk.export.issues');
        Route::get('export/issues-ageing', [ExportController::class, 'issuesAgeing'])->name('risk.export.issues-ageing');
        Route::get('export/loss-events', [ExportController::class, 'lossEvents'])->name('risk.export.loss-events');
        Route::get('export/quantification-results', [ExportController::class, 'quantificationResults'])->name('risk.export.quantification-results');
    });

    /* ------------------------------------------------------------------ */
    /*  Approvals */
    /* ------------------------------------------------------------------ */
    Route::get('approvals', [ApprovalController::class, 'dashboard'])
        ->middleware('permission:approval.view')->name('risk.approvals.dashboard');
    Route::get('approvals/history', [ApprovalController::class, 'history'])
        ->middleware('permission:approval.view')->name('risk.approvals.history');
    Route::post('approvals/{approval}/approve', [ApprovalController::class, 'approve'])
        ->middleware('permission:approval.act')->name('risk.approvals.approve');
    Route::post('approvals/{approval}/reject', [ApprovalController::class, 'reject'])
        ->middleware('permission:approval.act')->name('risk.approvals.reject');

    /* ------------------------------------------------------------------ */
    /*  Control Testing */
    /* ------------------------------------------------------------------ */
    Route::get('control-tests/dashboard', [ControlTestController::class, 'dashboard'])
        ->middleware('permission:control_test.view')->name('risk.control-tests.dashboard');
    Route::get('control-tests', [ControlTestController::class, 'index'])
        ->middleware('permission:control_test.view')->name('risk.control-tests.index');
    Route::get('control-tests/create', [ControlTestController::class, 'create'])
        ->middleware('permission:control_test.create')->name('risk.control-tests.create');
    Route::post('control-tests', [ControlTestController::class, 'store'])
        ->middleware('permission:control_test.create')->name('risk.control-tests.store');
    Route::get('control-tests/{controlTest}', [ControlTestController::class, 'show'])
        ->middleware('permission:control_test.view')->name('risk.control-tests.show');
    Route::get('control-tests/{controlTest}/edit', [ControlTestController::class, 'edit'])
        ->middleware('permission:control_test.edit')->name('risk.control-tests.edit');
    Route::put('control-tests/{controlTest}', [ControlTestController::class, 'update'])
        ->middleware('permission:control_test.edit')->name('risk.control-tests.update');
    Route::post('control-tests/{controlTest}/start', [ControlTestController::class, 'startTest'])
        ->middleware('permission:control_test.execute')->name('risk.control-tests.start');
    Route::post('control-tests/{controlTest}/complete', [ControlTestController::class, 'completeTest'])
        ->middleware('permission:control_test.execute')->name('risk.control-tests.complete');
    Route::post('control-tests/{controlTest}/review', [ControlTestController::class, 'reviewTest'])
        ->middleware('permission:control_test.review')->name('risk.control-tests.review');
    Route::post('control-tests/{controlTest}/resubmit', [ControlTestController::class, 'resubmit'])
        ->middleware('permission:control_test.execute')->name('risk.control-tests.resubmit');
    Route::post('control-tests/{controlTest}/evidence', [ControlTestController::class, 'uploadEvidence'])
        ->middleware('permission:control_test.execute')->name('risk.control-tests.upload-evidence');
    Route::get('control-tests/{controlTest}/evidence/{evidence}/download', [ControlTestController::class, 'downloadEvidence'])
        ->middleware('permission:control_test.view')->name('risk.control-tests.download-evidence');

    /* ------------------------------------------------------------------ */
    /*  Assessment Campaigns */
    /* ------------------------------------------------------------------ */
    Route::get('campaigns/dashboard', [CampaignController::class, 'dashboard'])
        ->middleware('permission:campaign.view')->name('risk.campaigns.dashboard');
    Route::get('campaigns', [CampaignController::class, 'index'])
        ->middleware('permission:campaign.view')->name('risk.campaigns.index');
    Route::get('campaigns/create', [CampaignController::class, 'create'])
        ->middleware('permission:campaign.create')->name('risk.campaigns.create');
    Route::post('campaigns', [CampaignController::class, 'store'])
        ->middleware('permission:campaign.create')->name('risk.campaigns.store');
    Route::get('campaigns/assignments/{assignment}/respond', [CampaignController::class, 'respond'])
        ->middleware('permission:campaign.respond')->name('risk.campaigns.respond');
    Route::post('campaigns/assignments/{assignment}/submit', [CampaignController::class, 'submitResponse'])
        ->middleware('permission:campaign.respond')->name('risk.campaigns.submit-response');
    Route::post('campaigns/assignments/{assignment}/review', [CampaignController::class, 'reviewAssignment'])
        ->middleware('permission:campaign.review')->name('risk.campaigns.review-assignment');
    Route::get('campaigns/{campaign}', [CampaignController::class, 'show'])
        ->middleware('permission:campaign.view')->name('risk.campaigns.show');
    Route::post('campaigns/{campaign}/assignments', [CampaignController::class, 'addAssignment'])
        ->middleware('permission:campaign.manage')->name('risk.campaigns.add-assignment');
    Route::post('campaigns/{campaign}/launch', [CampaignController::class, 'launch'])
        ->middleware('permission:campaign.manage')->name('risk.campaigns.launch');
    Route::post('campaigns/{campaign}/close', [CampaignController::class, 'closeCampaign'])
        ->middleware('permission:campaign.manage')->name('risk.campaigns.close');

    /* ------------------------------------------------------------------ */
    /*  Questionnaire Engine */
    /* ------------------------------------------------------------------ */
    Route::get('questionnaires', [QuestionnaireController::class, 'index'])
        ->middleware('permission:questionnaire.view')->name('risk.questionnaires.index');
    Route::get('questionnaires/create', [QuestionnaireController::class, 'create'])
        ->middleware('permission:questionnaire.create')->name('risk.questionnaires.create');
    Route::post('questionnaires', [QuestionnaireController::class, 'store'])
        ->middleware('permission:questionnaire.create')->name('risk.questionnaires.store');
    Route::get('questionnaires/{questionnaire}', [QuestionnaireController::class, 'show'])
        ->middleware('permission:questionnaire.view')->name('risk.questionnaires.show');
    Route::get('questionnaires/{questionnaire}/edit', [QuestionnaireController::class, 'edit'])
        ->middleware('permission:questionnaire.edit')->name('risk.questionnaires.edit');
    Route::post('questionnaires/{questionnaire}/sections', [QuestionnaireController::class, 'addSection'])
        ->middleware('permission:questionnaire.edit')->name('risk.questionnaires.add-section');
    Route::post('questionnaires/sections/{section}/questions', [QuestionnaireController::class, 'addQuestion'])
        ->middleware('permission:questionnaire.edit')->name('risk.questionnaires.add-question');
    Route::delete('questionnaires/questions/{question}', [QuestionnaireController::class, 'removeQuestion'])
        ->middleware('permission:questionnaire.edit')->name('risk.questionnaires.remove-question');
    Route::post('questionnaires/{questionnaire}/publish', [QuestionnaireController::class, 'publish'])
        ->middleware('permission:questionnaire.publish')->name('risk.questionnaires.publish');
    Route::get('question-library', [QuestionnaireController::class, 'library'])
        ->middleware('permission:questionnaire.view')->name('risk.questionnaires.library');
    Route::post('question-library', [QuestionnaireController::class, 'storeLibraryQuestion'])
        ->middleware('permission:questionnaire.create')->name('risk.questionnaires.store-library');

    /* ------------------------------------------------------------------ */
    /*  Workflow Engine */
    /* ------------------------------------------------------------------ */
    Route::get('workflows/dashboard', [WorkflowController::class, 'dashboard'])
        ->middleware('permission:workflow.view')->name('risk.workflows.dashboard');
    Route::get('workflows/definitions', [WorkflowController::class, 'definitions'])
        ->middleware('permission:workflow.view')->name('risk.workflows.definitions');
    Route::get('workflows/definitions/create', [WorkflowController::class, 'createDefinition'])
        ->middleware('permission:workflow.manage')->name('risk.workflows.create-definition');
    Route::post('workflows/definitions', [WorkflowController::class, 'storeDefinition'])
        ->middleware('permission:workflow.manage')->name('risk.workflows.store-definition');
    // WP-06: the designer. Editing a PUBLISHED definition opens a draft of the
    // next version rather than mutating the one running instances are pinned to.
    Route::get('workflows/definitions/{definition}/design', [WorkflowController::class, 'editDefinition'])
        ->middleware('permission:workflow.manage')->name('risk.workflows.edit-definition');
    Route::post('workflows/definitions/{definition}/publish', [WorkflowController::class, 'publishDefinition'])
        ->middleware('permission:workflow.manage')->name('risk.workflows.publish-definition');
    Route::post('workflows/definitions/{definition}/unpublish', [WorkflowController::class, 'unpublishDefinition'])
        ->middleware('permission:workflow.manage')->name('risk.workflows.unpublish-definition');
    Route::post('workflows/start', [WorkflowController::class, 'startWorkflow'])
        ->middleware('permission:workflow.manage')->name('risk.workflows.start');
    Route::get('workflows/{instance}', [WorkflowController::class, 'showInstance'])
        ->middleware('permission:workflow.view')->name('risk.workflows.show-instance');
    Route::post('workflows/{instance}/act', [WorkflowController::class, 'actOnWorkflow'])
        ->middleware('permission:workflow.act')->name('risk.workflows.act');

    /* ------------------------------------------------------------------ */
    /*  My tasks (WP-06 TASK 5 — the foundation for My Responsibilities) */
    /* ------------------------------------------------------------------ */
    Route::get('my-tasks', [MyTaskController::class, 'index'])
        ->middleware('permission:task.view')->name('risk.my-tasks.index');
    Route::get('my-tasks/{task}', [MyTaskController::class, 'show'])
        ->middleware('permission:task.view')->name('risk.my-tasks.show');
    Route::post('my-tasks/{task}/act', [MyTaskController::class, 'act'])
        ->middleware('permission:task.act')->name('risk.my-tasks.act');
    Route::post('my-tasks/{task}/delegate', [MyTaskController::class, 'delegate'])
        ->middleware('permission:task.act')->name('risk.my-tasks.delegate');
    Route::post('my-tasks/{task}/return', [MyTaskController::class, 'returnForRework'])
        ->middleware('permission:task.act')->name('risk.my-tasks.return');

    /* ------------------------------------------------------------------ */
    /*  Regulatory Compliance */
    /* ------------------------------------------------------------------ */
    Route::get('regulatory/dashboard', [RegulatoryComplianceController::class, 'dashboard'])
        ->middleware('permission:regulatory.view')->name('risk.regulatory.dashboard');
    Route::get('regulatory/calendar', [RegulatoryComplianceController::class, 'calendar'])
        ->middleware('permission:regulatory.view')->name('risk.regulatory.calendar');
    Route::get('regulatory/deadlines', [RegulatoryComplianceController::class, 'deadlines'])
        ->middleware('permission:regulatory.view')->name('risk.regulatory.deadlines');
    Route::get('regulatory/deadlines/create', [RegulatoryComplianceController::class, 'createDeadline'])
        ->middleware('permission:regulatory.manage')->name('risk.regulatory.create-deadline');
    Route::post('regulatory/deadlines', [RegulatoryComplianceController::class, 'storeDeadline'])
        ->middleware('permission:regulatory.manage')->name('risk.regulatory.store-deadline');
    Route::post('regulatory/deadlines/{deadline}/filing', [RegulatoryComplianceController::class, 'submitFiling'])
        ->middleware('permission:regulatory.file')->name('risk.regulatory.submit-filing');
    Route::get('regulatory/circulars', [RegulatoryComplianceController::class, 'circulars'])
        ->middleware('permission:regulatory.view')->name('risk.regulatory.circulars');
    Route::get('regulatory/circulars/create', [RegulatoryComplianceController::class, 'createCircular'])
        ->middleware('permission:regulatory.manage')->name('risk.regulatory.create-circular');
    Route::post('regulatory/circulars', [RegulatoryComplianceController::class, 'storeCircular'])
        ->middleware('permission:regulatory.manage')->name('risk.regulatory.store-circular');
    Route::get('regulatory/circulars/{circular}', [RegulatoryComplianceController::class, 'showCircular'])
        ->middleware('permission:regulatory.view')->name('risk.regulatory.show-circular');
    Route::patch('regulatory/circulars/{circular}/compliance', [RegulatoryComplianceController::class, 'updateCompliance'])
        ->middleware('permission:regulatory.manage')->name('risk.regulatory.update-compliance');
    Route::get('regulatory/taxonomy', [RegulatoryComplianceController::class, 'taxonomyIndex'])
        ->middleware('permission:regulatory.view')->name('risk.regulatory.taxonomy');
    Route::post('regulatory/taxonomy', [RegulatoryComplianceController::class, 'storeTaxonomy'])
        ->middleware('permission:regulatory.manage')->name('risk.regulatory.store-taxonomy');

    /* ------------------------------------------------------------------ */
    /*  Data Import */
    /* ------------------------------------------------------------------ */
    Route::get('imports', [DataImportController::class, 'index'])
        ->middleware('permission:import.view')->name('risk.imports.index');
    Route::get('imports/create', [DataImportController::class, 'create'])
        ->middleware('permission:import.create')->name('risk.imports.create');
    Route::post('imports/upload', [DataImportController::class, 'upload'])
        ->middleware('permission:import.create')->name('risk.imports.upload');
    Route::post('imports/{import}/process', [DataImportController::class, 'processImport'])
        ->middleware('permission:import.process')->name('risk.imports.process');
});
