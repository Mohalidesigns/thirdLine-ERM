<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Risk\DashboardController;
use App\Http\Controllers\Risk\RiskRegisterController;
use App\Http\Controllers\Risk\RiskAssessmentController;
use App\Http\Controllers\Risk\ControlController;
use App\Http\Controllers\Risk\TreatmentPlanController;
use App\Http\Controllers\Risk\KriController;
use App\Http\Controllers\Risk\RiskAppetiteController;
use App\Http\Controllers\Risk\LossEventController;
use App\Http\Controllers\Risk\IssueController;
use App\Http\Controllers\Risk\QuantificationController;
use App\Http\Controllers\Risk\RcsaController;
use App\Http\Controllers\Risk\AnalysisController;
use App\Http\Controllers\Risk\ReportController;
use App\Http\Controllers\Risk\AiIntelligenceController;
use App\Http\Controllers\Risk\AiToolsController;
use App\Http\Controllers\Risk\ScopingController;
use App\Http\Controllers\Risk\ExportController;
use App\Http\Controllers\Risk\ControlTestController;
use App\Http\Controllers\Risk\CampaignController;
use App\Http\Controllers\Risk\QuestionnaireController;
use App\Http\Controllers\Risk\WorkflowController;
use App\Http\Controllers\Risk\RegulatoryComplianceController;
use App\Http\Controllers\Risk\DataImportController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Admin\UserManagementController;
use App\Http\Controllers\Admin\OrganizationSettingsController;

Route::get('/', fn() => redirect('/risk/dashboard'));

// Auth routes (no middleware - public access)
Route::get('login', [AuthController::class, 'showLogin'])->name('login');
Route::post('login', [AuthController::class, 'login']);
Route::post('logout', [AuthController::class, 'logout'])->name('logout');
Route::get('forgot-password', [AuthController::class, 'showForgotPassword'])->name('password.request');
Route::post('forgot-password', [AuthController::class, 'sendResetLink'])->name('password.email');
Route::get('reset-password/{token}', [AuthController::class, 'showResetPassword'])->name('password.reset');
Route::post('reset-password', [AuthController::class, 'resetPassword'])->name('password.update');
Route::get('mfa/verify', [AuthController::class, 'showMfaVerify'])->name('mfa.verify');
Route::post('mfa/verify', [AuthController::class, 'verifyMfa']);

// MFA Setup (authenticated)
Route::middleware(['auth'])->group(function() {
    Route::get('mfa/setup', [AuthController::class, 'showMfaSetup'])->name('mfa.setup');
    Route::post('mfa/enable', [AuthController::class, 'enableMfa'])->name('mfa.enable');

    Route::get('notifications', [\App\Http\Controllers\NotificationController::class, 'index'])->name('notifications.index');
    Route::post('notifications/read-all', [\App\Http\Controllers\NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::get('notifications/{id}/read', [\App\Http\Controllers\NotificationController::class, 'read'])->name('notifications.read');
});

// Admin routes
Route::prefix('admin')->middleware(['auth'])->group(function () {
    Route::resource('users', UserManagementController::class)->names('admin.users');
    Route::patch('users/{user}/toggle-active', [UserManagementController::class, 'toggleActive'])->name('admin.users.toggle-active');
    Route::post('users/{user}/reset-password', [UserManagementController::class, 'resetPassword'])->name('admin.users.reset-password');

    Route::get('settings', [OrganizationSettingsController::class, 'index'])->name('admin.settings');
    Route::put('settings/profile', [OrganizationSettingsController::class, 'updateProfile'])->name('admin.settings.profile');
    Route::put('settings/thresholds', [OrganizationSettingsController::class, 'updateThresholds'])->name('admin.settings.thresholds');
    Route::put('settings/risk', [OrganizationSettingsController::class, 'updateRiskSettings'])->name('admin.settings.risk');
    Route::put('settings/notifications', [OrganizationSettingsController::class, 'updateNotificationPreferences'])->name('admin.settings.notifications');
});

Route::prefix('risk')->middleware(['auth'])->group(function () {
    // Dashboard
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('risk.dashboard');

    // Scoping / Entity Management
    Route::get('scoping/dashboard', [ScopingController::class, 'dashboard'])->name('risk.scoping.dashboard');
    Route::resource('scoping', ScopingController::class)->names('risk.scoping');

    // Risk Register
    Route::post('register/{register}/map-control', [RiskRegisterController::class, 'mapControl'])->name('risk.register.map-control');
    Route::resource('register', RiskRegisterController::class)->names('risk.register');

    // Risk Assessments
    Route::get('assessments', [RiskAssessmentController::class, 'index'])->name('risk.assessments.index');
    Route::get('assessments/create', [RiskAssessmentController::class, 'create'])->name('risk.assessments.create');
    Route::post('assessments', [RiskAssessmentController::class, 'store'])->name('risk.assessments.store');
    Route::get('assessments/{assessment}', [RiskAssessmentController::class, 'show'])->name('risk.assessments.show');
    Route::put('assessments/{assessment}', [RiskAssessmentController::class, 'update'])->name('risk.assessments.update');
    Route::post('assessments/{assessment}/submit', [RiskAssessmentController::class, 'submit'])->name('risk.assessments.submit');
    Route::post('assessments/{assessment}/approve', [RiskAssessmentController::class, 'approve'])->name('risk.assessments.approve');
    Route::post('assessments/{assessment}/reject', [RiskAssessmentController::class, 'reject'])->name('risk.assessments.reject');
    Route::post('assessments/{assessment}/resubmit', [RiskAssessmentController::class, 'resubmit'])->name('risk.assessments.resubmit');

    // Controls
    Route::resource('controls', ControlController::class)->names('risk.controls');
    Route::post('controls/{control}/link-risk', [ControlController::class, 'linkToRisk'])->name('risk.controls.link-risk');
    Route::delete('controls/{control}/unlink-risk/{risk}', [ControlController::class, 'unlinkFromRisk'])->name('risk.controls.unlink-risk');

    // Treatment Plans
    Route::get('treatments/dashboard', [TreatmentPlanController::class, 'dashboard'])->name('risk.treatments.dashboard');
    Route::get('treatments/review', [TreatmentPlanController::class, 'review'])->name('risk.treatments.review');
    Route::resource('treatments', TreatmentPlanController::class)->names('risk.treatments');
    Route::post('treatments/{treatment}/submit', [TreatmentPlanController::class, 'submitForReview'])->name('risk.treatments.submit');
    Route::post('treatments/{treatment}/approve', [TreatmentPlanController::class, 'approve'])->name('risk.treatments.approve');
    Route::post('treatments/{treatment}/reject', [TreatmentPlanController::class, 'reject'])->name('risk.treatments.reject');
    Route::post('treatments/{treatment}/resubmit', [TreatmentPlanController::class, 'resubmit'])->name('risk.treatments.resubmit');
    Route::post('treatments/{treatment}/comment', [TreatmentPlanController::class, 'comment'])->name('risk.treatments.comment');

    // KRI Monitoring
    Route::get('kri/dashboard', [KriController::class, 'dashboard'])->name('risk.kri.dashboard');
    Route::get('kri/thresholds', [KriController::class, 'thresholds'])->name('risk.kri.thresholds');
    Route::put('kri/thresholds', [KriController::class, 'updateThresholds'])->name('risk.kri.thresholds.update');
    Route::get('kri/breaches', [KriController::class, 'breaches'])->name('risk.kri.breaches');
    Route::post('kri/{kri}/measurement', [KriController::class, 'recordMeasurement'])->name('risk.kri.record-measurement');
    Route::resource('kri', KriController::class)->names('risk.kri');

    // Risk Appetite
    Route::get('appetite', [RiskAppetiteController::class, 'index'])->name('risk.appetite.index');
    Route::post('appetite', [RiskAppetiteController::class, 'store'])->name('risk.appetite.store');
    Route::put('appetite/{appetite}', [RiskAppetiteController::class, 'update'])->name('risk.appetite.update');

    // Loss Events
    Route::get('loss-events/dashboard', [LossEventController::class, 'dashboard'])->name('risk.loss-events.dashboard');
    Route::get('loss-events/near-misses', [LossEventController::class, 'nearMisses'])->name('risk.loss-events.near-misses');
    Route::post('loss-events/near-misses', [LossEventController::class, 'storeNearMiss'])->name('risk.loss-events.store-near-miss');
    Route::get('loss-events/near-misses/create', [LossEventController::class, 'createNearMiss'])->name('risk.loss-events.create-near-miss');
    Route::post('loss-events/convert-near-miss/{nearMiss}', [LossEventController::class, 'convertNearMiss'])->name('risk.loss-events.convert-near-miss');
    Route::get('loss-events/approvals', [LossEventController::class, 'approvals'])->name('risk.loss-events.approvals');
    Route::get('loss-events/rca', [LossEventController::class, 'rcaIndex'])->name('risk.loss-events.rca');
    Route::get('loss-events/reports', [LossEventController::class, 'reports'])->name('risk.loss-events.reports');
    Route::get('loss-events/{lossEvent}/rca', [LossEventController::class, 'rca'])->name('risk.loss-events.show-rca');
    Route::post('loss-events/{lossEvent}/rca', [LossEventController::class, 'storeRca'])->name('risk.loss-events.store-rca');
    Route::post('loss-events/{lossEvent}/rca/approve', [LossEventController::class, 'approveRca'])->name('risk.loss-events.approve-rca');
    Route::post('loss-events/{lossEvent}/attachments', [LossEventController::class, 'uploadAttachment'])->name('risk.loss-events.upload-attachment');
    Route::get('loss-events/{lossEvent}/attachments/{attachment}/download', [LossEventController::class, 'downloadAttachment'])->name('risk.loss-events.download-attachment');
    Route::delete('loss-events/{lossEvent}/attachments/{attachment}', [LossEventController::class, 'deleteAttachment'])->name('risk.loss-events.delete-attachment');
    Route::patch('loss-events/{lossEvent}/status', [LossEventController::class, 'updateStatus'])->name('risk.loss-events.update-status');
    Route::post('loss-events/{lossEvent}/approval', [LossEventController::class, 'submitApproval'])->name('risk.loss-events.submit-approval');
    Route::resource('loss-events', LossEventController::class)->names('risk.loss-events');

    // Issues
    Route::get('issues/dashboard', [IssueController::class, 'dashboard'])->name('risk.issues.dashboard');
    Route::get('issues/ageing', [IssueController::class, 'ageingReport'])->name('risk.issues.ageing');
    Route::get('issues/closure', [IssueController::class, 'closureList'])->name('risk.issues.closure');
    Route::patch('issues/{issue}/status', [IssueController::class, 'updateStatus'])->name('risk.issues.update-status');
    Route::post('issues/{issue}/remediation-actions', [IssueController::class, 'addRemediationAction'])->name('risk.issues.add-action');
    Route::patch('issues/{issue}/remediation-actions/{action}/complete', [IssueController::class, 'completeAction'])->name('risk.issues.complete-action');
    Route::post('issues/{issue}/progress-updates', [IssueController::class, 'addProgressUpdate'])->name('risk.issues.add-update');
    Route::post('issues/{issue}/request-closure', [IssueController::class, 'requestClosure'])->name('risk.issues.request-closure');
    Route::post('issues/{issue}/approve-closure', [IssueController::class, 'approveClosure'])->name('risk.issues.approve-closure');
    Route::post('issues/{issue}/reject-closure', [IssueController::class, 'rejectClosure'])->name('risk.issues.reject-closure');
    Route::get('issues/{issue}/attachments/{attachment}/download', [IssueController::class, 'downloadAttachment'])->name('risk.issues.download-attachment');
    Route::post('issues/{issue}/attachments', [IssueController::class, 'uploadAttachment'])->name('risk.issues.upload-attachment');
    Route::resource('issues', IssueController::class)->names('risk.issues');

    // Unified document/evidence repository
    Route::get('documents', [\App\Http\Controllers\Risk\DocumentRepositoryController::class, 'index'])->name('risk.documents.index');

    // Quantification
    Route::get('quantification/dashboard', [QuantificationController::class, 'dashboard'])->name('risk.quantification.dashboard');
    Route::get('quantification/scenarios', [QuantificationController::class, 'scenarios'])->name('risk.quantification.scenarios');
    Route::get('quantification/scenarios/create', [QuantificationController::class, 'createScenario'])->name('risk.quantification.create-scenario');
    Route::post('quantification/scenarios', [QuantificationController::class, 'storeScenario'])->name('risk.quantification.store-scenario');
    Route::get('quantification/scenarios/{scenario}', [QuantificationController::class, 'showScenario'])->name('risk.quantification.show-scenario');
    Route::get('quantification/scenarios/{scenario}/edit', [QuantificationController::class, 'editScenario'])->name('risk.quantification.edit-scenario');
    Route::put('quantification/scenarios/{scenario}', [QuantificationController::class, 'updateScenario'])->name('risk.quantification.update-scenario');
    Route::get('quantification/simulate', [QuantificationController::class, 'simulate'])->name('risk.quantification.simulate');
    Route::post('quantification/simulate', [QuantificationController::class, 'runSimulation'])->name('risk.quantification.run-simulation');
    Route::get('quantification/results', [QuantificationController::class, 'results'])->name('risk.quantification.results');
    Route::get('quantification/results/{simulation}', [QuantificationController::class, 'showResults'])->name('risk.quantification.show-results');
    Route::get('quantification/icaap', [QuantificationController::class, 'icaap'])->name('risk.quantification.icaap');
    Route::get('quantification/library', [QuantificationController::class, 'library'])->name('risk.quantification.library');
    Route::post('quantification/library/import/{libraryId}', [QuantificationController::class, 'importLibrary'])->name('risk.quantification.library.import');
    Route::get('quantification/settings', [QuantificationController::class, 'settings'])->name('risk.quantification.settings');
    Route::put('quantification/settings', [QuantificationController::class, 'updateSettings'])->name('risk.quantification.update-settings');
    Route::get('quantification/reports', [QuantificationController::class, 'reports'])->name('risk.quantification.reports');
    Route::get('quantification/reports/capital-adequacy', [QuantificationController::class, 'capitalAdequacyReport'])->name('risk.quantification.reports.capital-adequacy');
    Route::get('quantification/reports/stress-testing',   [QuantificationController::class, 'stressTestingReport'])->name('risk.quantification.reports.stress-testing');
    Route::get('quantification/reports/risk-contribution',[QuantificationController::class, 'riskContributionReport'])->name('risk.quantification.reports.risk-contribution');
    Route::get('quantification/reports/regulatory-pack',  [QuantificationController::class, 'regulatoryPack'])->name('risk.quantification.reports.regulatory-pack');

    // RCSA
    Route::get('rcsa/dashboard', [RcsaController::class, 'dashboard'])->name('risk.rcsa.dashboard');
    Route::get('rcsa/worksheet', [RcsaController::class, 'worksheet'])->name('risk.rcsa.worksheet');
    Route::post('rcsa/worksheet', [RcsaController::class, 'storeWorksheet'])->name('risk.rcsa.worksheet.store');
    Route::get('rcsa/controls', [RcsaController::class, 'controls'])->name('risk.rcsa.controls');
    Route::get('rcsa/matrix', [RcsaController::class, 'matrix'])->name('risk.rcsa.matrix');

    // Analysis
    Route::get('analysis/heatmap', [AnalysisController::class, 'heatmap'])->name('risk.analysis.heatmap');
    Route::get('analysis/bowtie', [AnalysisController::class, 'bowtie'])->name('risk.analysis.bowtie');
    Route::get('analysis/trends', [AnalysisController::class, 'trends'])->name('risk.analysis.trends');
    Route::get('analysis/correlation', [AnalysisController::class, 'correlation'])->name('risk.analysis.correlation');

    // Reports
    Route::get('reports/executive', [ReportController::class, 'executive'])->name('risk.reports.executive');
    Route::get('reports/board', [ReportController::class, 'board'])->name('risk.reports.board');
    Route::get('reports/regulatory', [ReportController::class, 'regulatory'])->name('risk.reports.regulatory');
    Route::get('reports/custom', [ReportController::class, 'custom'])->name('risk.reports.custom');
    Route::match(['get','post'], 'reports/custom/generate', [ReportController::class, 'generateCustom'])->name('risk.reports.custom.generate');

    // AI Intelligence
    Route::get('ai/predictive', [AiIntelligenceController::class, 'predictive'])->name('risk.ai.predictive');
    Route::get('ai/radar', [AiIntelligenceController::class, 'radar'])->name('risk.ai.radar');
    Route::get('ai/regulatory-pulse', [AiIntelligenceController::class, 'regulatoryPulse'])->name('risk.ai.regulatory-pulse');
    Route::get('ai/benchmarking', [AiIntelligenceController::class, 'benchmarking'])->name('risk.ai.benchmarking');

    // AI Tools (live LLM)
    Route::get('ai/tools/health', [AiToolsController::class, 'health'])->name('risk.ai.tools.health');
    Route::post('ai/tools/risk-statement', [AiToolsController::class, 'riskStatement'])->name('risk.ai.tools.risk-statement');
    Route::post('ai/tools/control-recommendations', [AiToolsController::class, 'controlRecommendations'])->name('risk.ai.tools.control-recommendations');
    Route::post('ai/tools/kri-suggestions', [AiToolsController::class, 'kriSuggestions'])->name('risk.ai.tools.kri-suggestions');
    Route::post('ai/tools/control-description', [AiToolsController::class, 'controlDescription'])->name('risk.ai.tools.control-description');
    Route::post('ai/tools/treatment-description', [AiToolsController::class, 'treatmentDescription'])->name('risk.ai.tools.treatment-description');
    Route::post('ai/tools/kri-description', [AiToolsController::class, 'kriDescription'])->name('risk.ai.tools.kri-description');
    Route::post('ai/tools/executive-narrative', [AiToolsController::class, 'executiveNarrative'])->name('risk.ai.tools.executive-narrative');

    // Exports (CSV Downloads)
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
    Route::match(['get','post'], 'loss-events/reports/cbn-orms', [ExportController::class, 'lossEventsCbnOrms'])->name('risk.export.loss-events.cbn-orms');
    Route::match(['get','post'], 'loss-events/reports/basel', [ExportController::class, 'lossEventsBasel'])->name('risk.export.loss-events.basel');
    Route::match(['get','post'], 'loss-events/reports/management', [ExportController::class, 'lossEventsManagement'])->name('risk.export.loss-events.management');
    Route::match(['get','post'], 'loss-events/reports/nfiu', [ExportController::class, 'lossEventsNfiu'])->name('risk.export.loss-events.nfiu');
    Route::match(['get','post'], 'loss-events/reports/trends', [ExportController::class, 'lossEventsTrends'])->name('risk.export.loss-events.trends');
    Route::match(['get','post'], 'loss-events/reports/export', [ExportController::class, 'lossEventsFullExport'])->name('risk.export.loss-events.full');

    // Approvals
    Route::get('approvals', [\App\Http\Controllers\Risk\ApprovalController::class, 'dashboard'])->name('risk.approvals.dashboard');
    Route::post('approvals/{approval}/approve', [\App\Http\Controllers\Risk\ApprovalController::class, 'approve'])->name('risk.approvals.approve');
    Route::post('approvals/{approval}/reject', [\App\Http\Controllers\Risk\ApprovalController::class, 'reject'])->name('risk.approvals.reject');
    Route::get('approvals/history', [\App\Http\Controllers\Risk\ApprovalController::class, 'history'])->name('risk.approvals.history');

    // ══════════════════════════════════════════════════════════════════
    // UPGRADE: Control Testing
    // ══════════════════════════════════════════════════════════════════
    Route::get('control-tests/dashboard', [ControlTestController::class, 'dashboard'])->name('risk.control-tests.dashboard');
    Route::get('control-tests', [ControlTestController::class, 'index'])->name('risk.control-tests.index');
    Route::get('control-tests/create', [ControlTestController::class, 'create'])->name('risk.control-tests.create');
    Route::post('control-tests', [ControlTestController::class, 'store'])->name('risk.control-tests.store');
    Route::get('control-tests/{controlTest}', [ControlTestController::class, 'show'])->name('risk.control-tests.show');
    Route::get('control-tests/{controlTest}/edit', [ControlTestController::class, 'edit'])->name('risk.control-tests.edit');
    Route::put('control-tests/{controlTest}', [ControlTestController::class, 'update'])->name('risk.control-tests.update');
    Route::post('control-tests/{controlTest}/start', [ControlTestController::class, 'startTest'])->name('risk.control-tests.start');
    Route::post('control-tests/{controlTest}/complete', [ControlTestController::class, 'completeTest'])->name('risk.control-tests.complete');
    Route::post('control-tests/{controlTest}/review', [ControlTestController::class, 'reviewTest'])->name('risk.control-tests.review');
    Route::post('control-tests/{controlTest}/resubmit', [ControlTestController::class, 'resubmit'])->name('risk.control-tests.resubmit');
    Route::post('control-tests/{controlTest}/evidence', [ControlTestController::class, 'uploadEvidence'])->name('risk.control-tests.upload-evidence');
    Route::get('control-tests/{controlTest}/evidence/{evidence}/download', [ControlTestController::class, 'downloadEvidence'])->name('risk.control-tests.download-evidence');

    // ══════════════════════════════════════════════════════════════════
    // UPGRADE: Assessment Campaigns
    // ══════════════════════════════════════════════════════════════════
    Route::get('campaigns/dashboard', [CampaignController::class, 'dashboard'])->name('risk.campaigns.dashboard');
    Route::get('campaigns', [CampaignController::class, 'index'])->name('risk.campaigns.index');
    Route::get('campaigns/create', [CampaignController::class, 'create'])->name('risk.campaigns.create');
    Route::post('campaigns', [CampaignController::class, 'store'])->name('risk.campaigns.store');
    Route::get('campaigns/{campaign}', [CampaignController::class, 'show'])->name('risk.campaigns.show');
    Route::post('campaigns/{campaign}/assignments', [CampaignController::class, 'addAssignment'])->name('risk.campaigns.add-assignment');
    Route::post('campaigns/{campaign}/launch', [CampaignController::class, 'launch'])->name('risk.campaigns.launch');
    Route::post('campaigns/{campaign}/close', [CampaignController::class, 'closeCampaign'])->name('risk.campaigns.close');
    Route::get('campaigns/assignments/{assignment}/respond', [CampaignController::class, 'respond'])->name('risk.campaigns.respond');
    Route::post('campaigns/assignments/{assignment}/submit', [CampaignController::class, 'submitResponse'])->name('risk.campaigns.submit-response');
    Route::post('campaigns/assignments/{assignment}/review', [CampaignController::class, 'reviewAssignment'])->name('risk.campaigns.review-assignment');

    // ══════════════════════════════════════════════════════════════════
    // UPGRADE: Questionnaire Engine
    // ══════════════════════════════════════════════════════════════════
    Route::get('questionnaires', [QuestionnaireController::class, 'index'])->name('risk.questionnaires.index');
    Route::get('questionnaires/create', [QuestionnaireController::class, 'create'])->name('risk.questionnaires.create');
    Route::post('questionnaires', [QuestionnaireController::class, 'store'])->name('risk.questionnaires.store');
    Route::get('questionnaires/{questionnaire}', [QuestionnaireController::class, 'show'])->name('risk.questionnaires.show');
    Route::get('questionnaires/{questionnaire}/edit', [QuestionnaireController::class, 'edit'])->name('risk.questionnaires.edit');
    Route::post('questionnaires/{questionnaire}/sections', [QuestionnaireController::class, 'addSection'])->name('risk.questionnaires.add-section');
    Route::post('questionnaires/sections/{section}/questions', [QuestionnaireController::class, 'addQuestion'])->name('risk.questionnaires.add-question');
    Route::delete('questionnaires/questions/{question}', [QuestionnaireController::class, 'removeQuestion'])->name('risk.questionnaires.remove-question');
    Route::post('questionnaires/{questionnaire}/publish', [QuestionnaireController::class, 'publish'])->name('risk.questionnaires.publish');
    Route::get('question-library', [QuestionnaireController::class, 'library'])->name('risk.questionnaires.library');
    Route::post('question-library', [QuestionnaireController::class, 'storeLibraryQuestion'])->name('risk.questionnaires.store-library');

    // ══════════════════════════════════════════════════════════════════
    // UPGRADE: Workflow Engine
    // ══════════════════════════════════════════════════════════════════
    Route::get('workflows/dashboard', [WorkflowController::class, 'dashboard'])->name('risk.workflows.dashboard');
    Route::get('workflows/definitions', [WorkflowController::class, 'definitions'])->name('risk.workflows.definitions');
    Route::get('workflows/definitions/create', [WorkflowController::class, 'createDefinition'])->name('risk.workflows.create-definition');
    Route::post('workflows/definitions', [WorkflowController::class, 'storeDefinition'])->name('risk.workflows.store-definition');
    Route::post('workflows/start', [WorkflowController::class, 'startWorkflow'])->name('risk.workflows.start');
    Route::get('workflows/{instance}', [WorkflowController::class, 'showInstance'])->name('risk.workflows.show-instance');
    Route::post('workflows/{instance}/act', [WorkflowController::class, 'actOnWorkflow'])->name('risk.workflows.act');

    // ══════════════════════════════════════════════════════════════════
    // UPGRADE: Regulatory Compliance
    // ══════════════════════════════════════════════════════════════════
    Route::get('regulatory/dashboard', [RegulatoryComplianceController::class, 'dashboard'])->name('risk.regulatory.dashboard');
    Route::get('regulatory/calendar', [RegulatoryComplianceController::class, 'calendar'])->name('risk.regulatory.calendar');
    Route::get('regulatory/deadlines', [RegulatoryComplianceController::class, 'deadlines'])->name('risk.regulatory.deadlines');
    Route::get('regulatory/deadlines/create', [RegulatoryComplianceController::class, 'createDeadline'])->name('risk.regulatory.create-deadline');
    Route::post('regulatory/deadlines', [RegulatoryComplianceController::class, 'storeDeadline'])->name('risk.regulatory.store-deadline');
    Route::post('regulatory/deadlines/{deadline}/filing', [RegulatoryComplianceController::class, 'submitFiling'])->name('risk.regulatory.submit-filing');
    Route::get('regulatory/circulars', [RegulatoryComplianceController::class, 'circulars'])->name('risk.regulatory.circulars');
    Route::get('regulatory/circulars/create', [RegulatoryComplianceController::class, 'createCircular'])->name('risk.regulatory.create-circular');
    Route::post('regulatory/circulars', [RegulatoryComplianceController::class, 'storeCircular'])->name('risk.regulatory.store-circular');
    Route::get('regulatory/circulars/{circular}', [RegulatoryComplianceController::class, 'showCircular'])->name('risk.regulatory.show-circular');
    Route::patch('regulatory/circulars/{circular}/compliance', [RegulatoryComplianceController::class, 'updateCompliance'])->name('risk.regulatory.update-compliance');
    Route::get('regulatory/taxonomy', [RegulatoryComplianceController::class, 'taxonomyIndex'])->name('risk.regulatory.taxonomy');
    Route::post('regulatory/taxonomy', [RegulatoryComplianceController::class, 'storeTaxonomy'])->name('risk.regulatory.store-taxonomy');

    // ══════════════════════════════════════════════════════════════════
    // UPGRADE: Data Import
    // ══════════════════════════════════════════════════════════════════
    Route::get('imports', [DataImportController::class, 'index'])->name('risk.imports.index');
    Route::get('imports/create', [DataImportController::class, 'create'])->name('risk.imports.create');
    Route::post('imports/upload', [DataImportController::class, 'upload'])->name('risk.imports.upload');
    Route::post('imports/{import}/process', [DataImportController::class, 'processImport'])->name('risk.imports.process');
});
