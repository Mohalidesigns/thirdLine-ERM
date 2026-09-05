# Migration parity checklist

Seeded in Phase 0 from `php artisan route:list --json` (188 named GET web routes). A route may only flip from Blade to Inertia when every cell in its row is filled; the Phase 7 sign-off is this table with no empty cells. Routes that return files or JSON (exports, downloads, suggest) are marked `n/a` in the page column when they flip to `Inertia::render`-free controllers.

| Route | URI | Permission | Old view | New page | Form Request | Policy | Grid | Widgets | Exports | Tests | Flipped on | Signed off by |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| `admin.api-tokens.index` | `admin/api-tokens` | `api.tokens` |  |  |  |  |  |  |  |  |  |  |
| `admin.builder` | `admin/builder` | `admin.metadata` |  |  |  |  |  |  |  |  |  |  |
| `admin.builder.attributes` | `admin/builder/object-types/{objectType}/attributes` | `admin.metadata` |  |  |  |  |  |  |  |  |  |  |
| `admin.builder.lifecycles` | `admin/builder/lifecycles` | `admin.metadata` |  |  |  |  |  |  |  |  |  |  |
| `admin.builder.object-types` | `admin/builder/object-types` | `admin.metadata` |  |  |  |  |  |  |  |  |  |  |
| `admin.builder.relationship-types` | `admin/builder/relationship-types` | `admin.metadata` |  |  |  |  |  |  |  |  |  |  |
| `admin.builder.scoring-profiles` | `admin/builder/scoring-profiles` | `admin.scoring` |  |  |  |  |  |  |  |  |  |  |
| `admin.configuration` | `admin/configuration` | `admin.configuration` |  |  |  |  |  |  |  |  |  |  |
| `admin.configuration.download` | `admin/configuration/{bundle}/download` | `admin.configuration` |  |  |  |  |  |  |  |  |  |  |
| `admin.connectors.index` | `admin/connectors` | `connector.view` |  |  |  |  |  |  |  |  |  |  |
| `admin.connectors.show` | `admin/connectors/{connector}` | `connector.view` |  |  |  |  |  |  |  |  |  |  |
| `admin.jobs.index` | `admin/jobs` | `job.view` |  |  |  |  |  |  |  |  |  |  |
| `admin.license` | `admin/settings/license` | `license.manage` | — (new) | Pages/Settings/License.jsx |  |  |  |  |  | Licensing/*, AdminNavigationTest | 2026-09-03 (Phase 0) |  |
| `admin.settings` | `admin/settings` | `admin.settings` |  |  |  |  |  |  |  |  |  |  |
| `admin.settings.sso` | `admin/settings/sso` | `admin.sso` |  |  |  |  |  |  |  |  |  |  |
| `admin.users.create` | `admin/users/create` | `admin.users` |  |  |  |  |  |  |  |  |  |  |
| `admin.users.edit` | `admin/users/{user}/edit` | `admin.users` |  |  |  |  |  |  |  |  |  |  |
| `admin.users.index` | `admin/users` | `admin.users` | admin/users/index.blade.php (deleted) | Pages/Admin/Users/Index.jsx |  |  | GridPresenter |  | risk.grids.export | Grid/* | 2026-09-03 (Phase 2) |  |
| `admin.users.show` | `admin/users/{user}` | `admin.users` |  |  |  |  |  |  |  |  |  |  |
| `admin.webhooks.deliveries` | `admin/webhooks/{webhook}/deliveries` | `webhook.view` |  |  |  |  |  |  |  |  |  |  |
| `admin.webhooks.index` | `admin/webhooks` | `webhook.view` |  |  |  |  |  |  |  |  |  |  |
| `hq.index` | `hq` | `hq.view` | — (redirect) | n/a (redirects to hq.show) |  |  |  |  |  | Inertia/HqPagesTest | 2026-09-03 (Phase 2) |  |
| `hq.show` | `hq/{object}` | `hq.view` | hq/show.blade.php, hq/empty.blade.php, hq/partials/tree.blade.php (deleted) | Pages/Hq/Show.jsx, Pages/Hq/Empty.jsx |  |  |  | Components/Widget.jsx + widgets/renderers/* | risk.widgets.export | Inertia/HqPagesTest, Widgets/DashboardPublishingTest, Widgets/HqSurfacesTest | 2026-09-03 (Phase 2) |  |
| `login` | `login` | `` | auth/login.blade.php (deleted) | Pages/Auth/Login.jsx | Auth/LoginRequest |  |  |  |  | Inertia/AuthPagesTest, AuthenticationRateLimitTest | 2026-09-03 (Phase 1) |  |
| `mfa.setup` | `mfa/setup` | `` | auth/mfa-setup.blade.php (deleted) | Pages/Auth/MfaSetup.jsx |  |  |  |  |  | Auth/MfaSetupTest, MfaFeatureGateTest | 2026-09-03 (Phase 1) |  |
| `mfa.verify` | `mfa/verify` | `` | auth/mfa-verify.blade.php (deleted) | Pages/Auth/MfaVerify.jsx |  |  |  |  |  | Auth/MfaLoginFlowTest, MfaEnforcementTest | 2026-09-03 (Phase 1) |  |
| `my.index` | `my` | `my.view` | my/index.blade.php (deleted) | Pages/My/Index.jsx |  |  |  |  |  | Inertia/MyPageTest | 2026-09-03 (Phase 0) |  |
| `notifications.index` | `notifications` | `notification.view` | notifications/index.blade.php (deleted) | Pages/Notifications/Index.jsx |  |  |  |  |  | Inertia/AuthPagesTest, NotificationActionUrlTest | 2026-09-03 (Phase 1) |  |
| `notifications.read` | `notifications/{id}/read` | `notification.view` |  |  |  |  |  |  |  |  |  |  |
| `password.request` | `forgot-password` | `` | auth/forgot-password.blade.php (deleted) | Pages/Auth/ForgotPassword.jsx |  |  |  |  |  | Inertia/AuthPagesTest | 2026-09-03 (Phase 1) |  |
| `password.reset` | `reset-password/{token}` | `` | auth/reset-password.blade.php (deleted) | Pages/Auth/ResetPassword.jsx |  |  |  |  |  | Inertia/AuthPagesTest | 2026-09-03 (Phase 1) |  |
| `risk.ai.predictive` | `risk/ai/predictive` | `ai.view` |  |  |  |  |  |  |  |  |  |  |
| `risk.ai.radar` | `risk/ai/radar` | `ai.view` |  |  |  |  |  |  |  |  |  |  |
| `risk.ai.regulatory-pulse` | `risk/ai/regulatory-pulse` | `ai.view` |  |  |  |  |  |  |  |  |  |  |
| `risk.ai.tools.health` | `risk/ai/tools/health` | `ai.view` |  |  |  |  |  |  |  |  |  |  |
| `risk.analysis.bowtie` | `risk/analysis/bowtie` | `analysis.view` |  |  |  |  |  |  |  |  |  |  |
| `risk.analysis.correlation` | `risk/analysis/correlation` | `analysis.view` |  |  |  |  |  |  |  |  |  |  |
| `risk.analysis.heatmap` | `risk/analysis/heatmap` | `analysis.view` |  |  |  |  |  |  |  |  |  |  |
| `risk.analysis.trends` | `risk/analysis/trends` | `analysis.view` |  |  |  |  |  |  |  |  |  |  |
| `risk.appetite.index` | `risk/appetite` | `appetite.view` | risk/appetite/index.blade.php (deleted) | Pages/Appetite/Index.jsx | Appetite/{StoreRiskAppetiteRequest,UpdateRiskAppetiteRequest} | RiskAppetitePolicy |  | appetite_position (chartConfigs) | risk.export.appetite (unchanged) | Appetite/AppetitePageTest, Characterisation/AppetitePositionTest | 2026-09-03 (Phase 3) |  |
| `risk.approvals.dashboard` | `risk/approvals` | `approval.view` | risk/approvals/dashboard.blade.php (deleted) | Pages/Approvals/Dashboard.jsx | Approvals/{ApproveRequest,RejectRequest} | ApprovalRequestPolicy |  |  |  | Workflow/WorkflowPagesTest | 2026-09-03 (Phase 3) |  |
| `risk.approvals.history` | `risk/approvals/history` | `approval.view` | risk/approvals/history.blade.php (deleted) | Pages/Approvals/History.jsx |  |  | GridPresenter |  | risk.grids.export | Grid/* | 2026-09-03 (Phase 2) |  |
| `risk.assessments.create` | `risk/assessments/create` | `assessment.create` | risk/assessments/{create,select-risk}.blade.php (deleted) | Pages/Assessments/Create.jsx; Pages/Assessments/SelectRisk.jsx (no risk_id) | StoreAssessmentRequest, PreviewAssessmentRequest | RiskAssessmentPolicy | n/a | n/a | n/a | Assessments/{AssessmentPagesTest,AssessmentRequestsTest,AssessmentPreviewTest}, Characterisation/AssessmentChainServiceTest | 2026-09-04 (Phase 3.3) |  |
| `risk.assessments.edit` | `risk/assessments/{assessment}/edit` | `assessment.create` | risk/assessments/create.blade.php (deleted) | Pages/Assessments/Create.jsx (prefilled) | UpdateAssessmentRequest | RiskAssessmentPolicy | n/a | n/a | n/a | Assessments/AssessmentPagesTest | 2026-09-04 (Phase 3.3) |  |
| `risk.assessments.index` | `risk/assessments` | `assessment.view` | risk/assessments/index.blade.php (deleted) | Pages/Assessments/Index.jsx |  |  | GridPresenter |  | risk.grids.export | Grid/* | 2026-09-03 (Phase 2) |  |
| `risk.assessments.show` | `risk/assessments/{assessment}` | `assessment.view` | risk/assessments/show.blade.php (deleted) | Pages/Assessments/Show.jsx | n/a (submit/approve/reject/resubmit validate inline) | RiskAssessmentPolicy | n/a | RadarChart, GroupedBarChart (house SVG) | n/a | Assessments/{AssessmentPagesTest,RiskAssessmentPolicyTest} | 2026-09-04 (Phase 3.3) |  |
| `risk.assessments.preview` | `risk/assessments/preview` | `assessment.create` | — (new; replaces the Alpine assessmentChain mirror) | n/a (JSON) | PreviewAssessmentRequest | RiskAssessmentPolicy | n/a | n/a | n/a | Assessments/AssessmentPreviewTest | 2026-09-04 (Phase 3.3) |  |
| `risk.campaigns.create` | `risk/campaigns/create` | `campaign.create` | risk/campaigns/create.blade.php (deleted) | Pages/Campaigns/Create.jsx | StoreCampaignRequest | AssessmentCampaignPolicy |  |  |  | Campaigns/* | 2026-09-05 (Phase 4.5) |  |
| `risk.campaigns.dashboard` | `risk/campaigns/dashboard` | `campaign.view` | risk/campaigns/dashboard.blade.php (deleted) | Pages/Campaigns/Dashboard.jsx | — | AssessmentCampaignPolicy |  |  |  | Characterisation/CampaignDashboardFiguresTest, Campaigns/* | 2026-09-05 (Phase 4.5) |  |
| `risk.campaigns.index` | `risk/campaigns` | `campaign.view` | risk/campaigns/index.blade.php (deleted) | Pages/Campaigns/Index.jsx |  |  | GridPresenter |  | risk.grids.export | Grid/* | 2026-09-03 (Phase 2) |  |
| `risk.campaigns.respond` | `risk/campaigns/assignments/{assignment}/respond` | `campaign.respond` | risk/campaigns/respond.blade.php (deleted) | Pages/Campaigns/Respond.jsx | SubmitResponseRequest | AssessmentCampaignPolicy |  |  |  | Campaigns/CampaignPagesTest | 2026-09-05 (Phase 4.5) |  |
| `risk.campaigns.show` | `risk/campaigns/{campaign}` | `campaign.view` | risk/campaigns/show.blade.php (deleted) | Pages/Campaigns/Show.jsx | AddAssignmentRequest, ReviewAssignmentRequest | AssessmentCampaignPolicy |  |  |  | Campaigns/*, RcsaWorksheetSubmissionTest | 2026-09-05 (Phase 4.5) |  |
| `risk.campaigns.submission` | `risk/campaigns/assignments/{assignment}/submission` | `campaign.view` | risk/campaigns/submission.blade.php (deleted) | Pages/Campaigns/Submission.jsx | ReviewAssignmentRequest | AssessmentCampaignPolicy |  |  |  | Campaigns/*, RcsaWorksheetSubmissionTest | 2026-09-05 (Phase 4.5) |  |
| `risk.control-tests.create` | `risk/control-tests/create` | `control_test.create` | risk/controls/tests/create.blade.php (deleted) | Pages/ControlTests/Create.jsx | StoreControlTestRequest | ControlTestPolicy | n/a | n/a | n/a | Controls/{ControlTestPagesTest,ControlPoliciesTest} | 2026-09-04 (Phase 3.4) |  |
| `risk.control-tests.dashboard` | `risk/control-tests/dashboard` | `control_test.view` | risk/controls/testing-dashboard.blade.php (deleted) | Pages/ControlTests/Dashboard.jsx | n/a | ControlTestPolicy | n/a | KpiCard row | n/a | Characterisation/ControlTestingDashboardTest, Controls/ControlTestPagesTest | 2026-09-04 (Phase 3.4) |  |
| `risk.control-tests.download-evidence` | `risk/control-tests/{controlTest}/evidence/{evidence}/download` | `control_test.view` |  |  |  |  |  |  |  |  |  |  |
| `risk.control-tests.edit` | `risk/control-tests/{controlTest}/edit` | `control_test.edit` | risk/controls/tests/edit.blade.php (deleted) | Pages/ControlTests/Edit.jsx | UpdateControlTestRequest | ControlTestPolicy | n/a | n/a | n/a | Controls/ControlTestPagesTest | 2026-09-04 (Phase 3.4) |  |
| `risk.control-tests.index` | `risk/control-tests` | `control_test.view` | risk/controls/tests/index.blade.php (deleted) | Pages/ControlTests/Index.jsx |  |  | GridPresenter |  | risk.grids.export | Grid/* | 2026-09-03 (Phase 2) |  |
| `risk.control-tests.show` | `risk/control-tests/{controlTest}` | `control_test.view` | risk/controls/tests/show.blade.php (deleted) | Pages/ControlTests/Show.jsx | ExecuteControlTestRequest, ReviewControlTestRequest, UploadControlTestEvidenceRequest | ControlTestPolicy | n/a | n/a | n/a | Controls/ControlTestPagesTest | 2026-09-04 (Phase 3.4) |  |
| `risk.controls.create` | `risk/controls/create` | `control.create` | risk/controls/create.blade.php (deleted) | Pages/Controls/Create.jsx | StoreControlRequest | ControlPolicy | n/a | n/a | n/a | Controls/ControlPagesTest, Metadata/DynamicRendererTest | 2026-09-04 (Phase 3.4) |  |
| `risk.controls.edit` | `risk/controls/{control}/edit` | `control.edit` | risk/controls/edit.blade.php (deleted) | Pages/Controls/Edit.jsx | UpdateControlRequest | ControlPolicy | n/a | n/a | n/a | Controls/ControlPagesTest, Metadata/DynamicRendererTest | 2026-09-04 (Phase 3.4) |  |
| `risk.controls.index` | `risk/controls` | `control.view` | risk/controls/index.blade.php (deleted) | Pages/Controls/Index.jsx |  |  | GridPresenter |  | risk.grids.export | Grid/ControlsGridTest | 2026-09-03 (Phase 2) |  |
| `risk.controls.show` | `risk/controls/{control}` | `control.view` | risk/controls/show.blade.php (deleted) | Pages/Controls/Show.jsx | LinkControlRiskRequest | ControlPolicy | n/a | n/a | n/a | Controls/ControlPagesTest, Metadata/DynamicDetailIntegrationTest | 2026-09-04 (Phase 3.4) |  |
| `risk.dashboard` | `risk/dashboard` | `dashboard.view` |  |  |  |  |  |  |  |  |  |  |
| `risk.dashboards.create` | `risk/dashboards/create` | `dashboard.manage` | — (redirect) | n/a (creates, redirects to edit) |  |  |  |  |  | Widgets/DashboardPublishingTest | 2026-09-03 (Phase 2) |  |
| `risk.dashboards.edit` | `risk/dashboards/{dashboard}/edit` | `dashboard.manage` | risk/dashboards/edit.blade.php + livewire/widgets/dashboard-builder.blade.php (deleted) | Pages/Dashboards/Edit.jsx (Components/DashboardBuilder.jsx) | inline validate() — see phase-2-notes |  |  | DashboardEditor |  | Widgets/DashboardLayoutRoundTripTest, Widgets/DashboardPublishingTest | 2026-09-03 (Phase 2) |  |
| `risk.dashboards.index` | `risk/dashboards` | `dashboard.manage` | risk/dashboards/index.blade.php (deleted) | Pages/Dashboards/Index.jsx |  |  |  |  |  | Widgets/DashboardLayoutRoundTripTest | 2026-09-03 (Phase 2) |  |
| `risk.documents.index` | `risk/documents` | `document.view` |  |  |  |  |  |  |  |  |  |  |
| `risk.emerging.create` | `risk/emerging-risks/create` | `risk.create` |  |  |  |  |  |  |  |  |  |  |
| `risk.emerging.edit` | `risk/emerging-risks/{emerging}/edit` | `risk.edit` |  |  |  |  |  |  |  |  |  |  |
| `risk.emerging.index` | `risk/emerging-risks` | `risk.view` | risk/emerging/index.blade.php (deleted) | Pages/Emerging/Index.jsx |  |  | GridPresenter |  | risk.grids.export | Grid/* | 2026-09-03 (Phase 2) |  |
| `risk.export.appetite` | `risk/export/appetite` | `report.export` |  |  |  |  |  |  |  |  |  |  |
| `risk.export.assessments` | `risk/export/assessments` | `report.export` |  |  |  |  |  |  |  |  |  |  |
| `risk.export.controls` | `risk/export/controls` | `report.export` |  |  |  |  |  |  |  |  |  |  |
| `risk.export.dashboard` | `risk/export/dashboard` | `report.export` |  |  |  |  |  |  |  |  |  |  |
| `risk.export.issues` | `risk/export/issues` | `report.export` |  |  |  |  |  |  |  |  |  |  |
| `risk.export.issues-ageing` | `risk/export/issues-ageing` | `report.export` |  |  |  |  |  |  |  |  |  |  |
| `risk.export.loss-events` | `risk/export/loss-events` | `report.export` |  |  |  |  |  |  |  |  |  |  |
| `risk.export.loss-events.basel` | `risk/loss-events/reports/basel` | `report.export` |  |  |  |  |  |  |  |  |  |  |
| `risk.export.loss-events.cbn-orms` | `risk/loss-events/reports/cbn-orms` | `report.export` |  |  |  |  |  |  |  |  |  |  |
| `risk.export.loss-events.full` | `risk/loss-events/reports/export` | `report.export` |  |  |  |  |  |  |  |  |  |  |
| `risk.export.loss-events.management` | `risk/loss-events/reports/management` | `report.export` |  |  |  |  |  |  |  |  |  |  |
| `risk.export.loss-events.nfiu` | `risk/loss-events/reports/nfiu` | `report.export` |  |  |  |  |  |  |  |  |  |  |
| `risk.export.loss-events.trends` | `risk/loss-events/reports/trends` | `report.export` |  |  |  |  |  |  |  |  |  |  |
| `risk.export.quantification-results` | `risk/export/quantification-results` | `report.export` |  |  |  |  |  |  |  |  |  |  |
| `risk.export.rcsa-matrix` | `risk/export/rcsa-matrix` | `report.export` |  |  |  |  |  |  |  |  |  |  |
| `risk.export.register` | `risk/export/register` | `report.export` |  |  |  |  |  |  |  |  |  |  |
| `risk.imports.create` | `risk/imports/create` | `import.create` |  |  |  |  |  |  |  |  |  |  |
| `risk.imports.index` | `risk/imports` | `import.view` | risk/imports/index.blade.php (deleted) | Pages/Imports/Index.jsx |  |  | GridPresenter |  | risk.grids.export | Grid/* | 2026-09-03 (Phase 2) |  |
| `risk.issues.ageing` | `risk/issues/ageing` | `issue.view` | risk/issues/ageing.blade.php (deleted) | Pages/Issues/Ageing.jsx |  | IssuePolicy |  |  |  | Issues/IssuePagesTest, Characterisation/IssueDashboardFiguresTest | 2026-09-04 (Phase 4.4) |  |
| `risk.issues.closure` | `risk/issues/closure` | `issue.close` | risk/issues/closure.blade.php (deleted) | Pages/Issues/Closure.jsx | RejectIssueClosureRequest | IssuePolicy |  |  |  | Issues/IssuePagesTest, Characterisation/IssueDashboardFiguresTest | 2026-09-04 (Phase 4.4) |  |
| `risk.issues.create` | `risk/issues/create` | `issue.create` | risk/issues/create.blade.php (deleted) | Pages/Issues/Create.jsx | StoreIssueRequest | IssuePolicy |  |  |  | Issues/IssuePagesTest, Characterisation/IssueDashboardFiguresTest | 2026-09-04 (Phase 4.4) |  |
| `risk.issues.dashboard` | `risk/issues/dashboard` | `issue.view` | risk/issues/dashboard.blade.php (deleted) | Pages/Issues/Dashboard.jsx |  | IssuePolicy |  |  |  | Issues/IssuePagesTest, Characterisation/IssueDashboardFiguresTest | 2026-09-04 (Phase 4.4) |  |
| `risk.issues.download-attachment` | `risk/issues/{issue}/attachments/{attachment}/download` | `issue.view` |  |  |  |  |  |  |  |  |  |  |
| `risk.issues.edit` | `risk/issues/{issue}/edit` | `issue.edit` | risk/issues/edit.blade.php (deleted) | Pages/Issues/Edit.jsx | UpdateIssueRequest | IssuePolicy |  |  |  | Issues/IssuePagesTest, Characterisation/IssueDashboardFiguresTest | 2026-09-04 (Phase 4.4) |  |
| `risk.issues.index` | `risk/issues` | `issue.view` | risk/issues/index.blade.php (deleted) | Pages/Issues/Index.jsx |  |  | GridPresenter |  | risk.grids.export | Grid/* | 2026-09-03 (Phase 2) |  |
| `risk.issues.show` | `risk/issues/{issue}` | `issue.view` | risk/issues/show.blade.php (deleted) | Pages/Issues/Show.jsx | UpdateIssueStatusRequest, StoreProgressUpdateRequest, StoreRemediationActionRequest, RequestIssueClosureRequest | IssuePolicy |  |  |  | Issues/IssuePagesTest, Characterisation/IssueDashboardFiguresTest | 2026-09-04 (Phase 4.4) |  |
| `risk.kri.breaches` | `risk/kri/breaches` | `kri.view` | risk/kri/breaches.blade.php (deleted) | Pages/Kri/Breaches.jsx |  |  | GridPresenter |  | risk.grids.export | Grid/* | 2026-09-03 (Phase 2) |  |
| `risk.kri.create` | `risk/kri/create` | `kri.create` | risk/kri/create.blade.php (deleted) | Pages/Kri/Create.jsx | StoreKriRequest | KeyRiskIndicatorPolicy |  |  |  | Kri/KriPagesTest, KriPolicyTest, KriThresholdsTest, Characterisation/KriDashboardFiguresTest | 2026-09-04 (Phase 4.1) |  |
| `risk.kri.dashboard` | `risk/kri/dashboard` | `kri.view` | risk/kri/dashboard.blade.php (deleted) | Pages/Kri/Dashboard.jsx |  | KeyRiskIndicatorPolicy |  | house SVG/CSS charts (see notes) |  | Kri/KriPagesTest, KriPolicyTest, KriThresholdsTest, Characterisation/KriDashboardFiguresTest | 2026-09-04 (Phase 4.1) |  |
| `risk.kri.edit` | `risk/kri/{kri}/edit` | `kri.edit` | risk/kri/edit.blade.php (deleted) | Pages/Kri/Edit.jsx | UpdateKriRequest | KeyRiskIndicatorPolicy |  |  |  | Kri/KriPagesTest, KriPolicyTest, KriThresholdsTest, Characterisation/KriDashboardFiguresTest | 2026-09-04 (Phase 4.1) |  |
| `risk.kri.index` | `risk/kri` | `kri.view` | risk/kri/index.blade.php (deleted) | Pages/Kri/Index.jsx |  |  | GridPresenter |  | risk.grids.export | Grid/* | 2026-09-03 (Phase 2) |  |
| `risk.kri.show` | `risk/kri/{kri}` | `kri.view` | risk/kri/show.blade.php (deleted) | Pages/Kri/Show.jsx | RecordKriMeasurementRequest | KeyRiskIndicatorPolicy |  |  |  | Kri/KriPagesTest, KriPolicyTest, KriThresholdsTest, Characterisation/KriDashboardFiguresTest | 2026-09-04 (Phase 4.1) |  |
| `risk.kri.thresholds` | `risk/kri/thresholds` | `kri.view` | risk/kri/thresholds.blade.php (deleted) | Pages/Kri/Thresholds.jsx | UpdateKriThresholdsRequest | KeyRiskIndicatorPolicy |  |  |  | Kri/KriPagesTest, KriPolicyTest, KriThresholdsTest, Characterisation/KriDashboardFiguresTest | 2026-09-04 (Phase 4.1) |  |
| `risk.loss-events.approvals` | `risk/loss-events/approvals` | `loss_event.approve` | risk/loss-events/approvals.blade.php (deleted) | Pages/LossEvents/Approvals.jsx |  | LossEventPolicy |  |  |  | LossEvents/LossEventPagesTest, LossEventPolicyTest, LossEventThresholdEvaluationTest, Characterisation/LossEvent* | 2026-09-04 (Phase 4.3) |  |
| `risk.loss-events.create` | `risk/loss-events/create` | `loss_event.create` | risk/loss-events/create.blade.php (deleted) | Pages/LossEvents/Create.jsx | StoreLossEventRequest | LossEventPolicy |  |  |  | LossEvents/LossEventPagesTest, LossEventPolicyTest, LossEventThresholdEvaluationTest, Characterisation/LossEvent* | 2026-09-04 (Phase 4.3) |  |
| `risk.loss-events.create-near-miss` | `risk/loss-events/near-misses/create` | `loss_event.create` | risk/loss-events/create-near-miss.blade.php (deleted) | Pages/LossEvents/CreateNearMiss.jsx | StoreNearMissRequest | LossEventPolicy |  |  |  | LossEvents/LossEventPagesTest, LossEventPolicyTest, LossEventThresholdEvaluationTest, Characterisation/LossEvent* | 2026-09-04 (Phase 4.3) |  |
| `risk.loss-events.dashboard` | `risk/loss-events/dashboard` | `loss_event.view` | risk/loss-events/dashboard.blade.php (deleted) | Pages/LossEvents/Dashboard.jsx |  | LossEventPolicy |  | house SVG/CSS charts (see notes) |  | LossEvents/LossEventPagesTest, LossEventPolicyTest, LossEventThresholdEvaluationTest, Characterisation/LossEvent* | 2026-09-04 (Phase 4.3) |  |
| `risk.loss-events.download-attachment` | `risk/loss-events/{lossEvent}/attachments/{attachment}/download` | `loss_event.view` |  |  |  |  |  |  |  |  |  |  |
| `risk.loss-events.edit` | `risk/loss-events/{loss_event}/edit` | `loss_event.edit` | risk/loss-events/edit.blade.php (deleted) | Pages/LossEvents/Edit.jsx | UpdateLossEventRequest | LossEventPolicy |  |  |  | LossEvents/LossEventPagesTest, LossEventPolicyTest, LossEventThresholdEvaluationTest, Characterisation/LossEvent* | 2026-09-04 (Phase 4.3) |  |
| `risk.loss-events.index` | `risk/loss-events` | `loss_event.view` | risk/loss-events/index.blade.php (deleted) | Pages/LossEvents/Index.jsx |  |  | GridPresenter |  | risk.grids.export | Grid/* | 2026-09-03 (Phase 2) |  |
| `risk.loss-events.near-misses` | `risk/loss-events/near-misses` | `loss_event.view` | risk/loss-events/near-misses.blade.php (deleted) | Pages/LossEvents/NearMisses.jsx |  |  | GridPresenter |  | risk.grids.export | Grid/* | 2026-09-03 (Phase 2) |  |
| `risk.loss-events.rca` | `risk/loss-events/rca` | `loss_event.view` | risk/loss-events/rca.blade.php (deleted) | Pages/LossEvents/Rca.jsx |  | LossEventPolicy |  |  |  | LossEvents/LossEventPagesTest, LossEventPolicyTest, LossEventThresholdEvaluationTest, Characterisation/LossEvent* | 2026-09-04 (Phase 4.3) |  |
| `risk.loss-events.reports` | `risk/loss-events/reports` | `loss_event.view` | risk/loss-events/reports.blade.php (deleted) | Pages/LossEvents/Reports.jsx |  | LossEventPolicy |  |  | risk.export.loss-events.* | LossEvents/LossEventPagesTest, LossEventPolicyTest, LossEventThresholdEvaluationTest, Characterisation/LossEvent* | 2026-09-04 (Phase 4.3) |  |
| `risk.loss-events.show` | `risk/loss-events/{loss_event}` | `loss_event.view` | risk/loss-events/show.blade.php (deleted) | Pages/LossEvents/Show.jsx | StoreLossEventRcaRequest, UpdateLossEventStatusRequest, SubmitLossEventApprovalRequest | LossEventPolicy |  |  |  | LossEvents/LossEventPagesTest, LossEventPolicyTest, LossEventThresholdEvaluationTest, Characterisation/LossEvent* | 2026-09-04 (Phase 4.3) |  |
| `risk.loss-events.show-rca` | `risk/loss-events/{lossEvent}/rca` | `loss_event.view` |  |  |  |  |  |  |  |  |  |  |
| `risk.my-tasks.index` | `risk/my-tasks` | `task.view` | risk/my-tasks/index.blade.php (deleted) | Pages/MyTasks/Index.jsx | Workflow/{ActOnTaskRequest,DelegateTaskRequest,ReturnTaskRequest} | WorkflowTaskPolicy |  |  |  | Workflow/WorkflowPagesTest, Workflow/MyTasksTest | 2026-09-03 (Phase 3) |  |
| `risk.my-tasks.show` | `risk/my-tasks/{task}` | `task.view` | risk/my-tasks/show.blade.php (deleted) | Pages/MyTasks/Show.jsx | Workflow/{ActOnTaskRequest,DelegateTaskRequest,ReturnTaskRequest} | WorkflowTaskPolicy |  |  |  | Workflow/WorkflowPagesTest, Workflow/MyTasksTest | 2026-09-03 (Phase 3) |  |
| `risk.periods.index` | `risk/periods` | `period.view` | risk/periods/index.blade.php (deleted) | Pages/Periods/Index.jsx | ReopenPeriodRequest | PeriodPolicy |  |  |  | Periods/PeriodAndThresholdPagesTest | 2026-09-04 (Phase 4.2) |  |
| `risk.periods.select` | `risk/periods/select` | `dashboard.view` |  |  |  |  |  |  |  |  |  |  |
| `risk.quantification.create-scenario` | `risk/quantification/scenarios/create` | `quantification.create` |  |  |  |  |  |  |  |  |  |  |
| `risk.quantification.dashboard` | `risk/quantification/dashboard` | `quantification.view` |  |  |  |  |  |  |  |  |  |  |
| `risk.quantification.edit-scenario` | `risk/quantification/scenarios/{scenario}/edit` | `quantification.create` |  |  |  |  |  |  |  |  |  |  |
| `risk.quantification.icaap` | `risk/quantification/icaap` | `quantification.view` |  |  |  |  |  |  |  |  |  |  |
| `risk.quantification.library` | `risk/quantification/library` | `quantification.view` |  |  |  |  |  |  |  |  |  |  |
| `risk.quantification.reports` | `risk/quantification/reports` | `quantification.view` |  |  |  |  |  |  |  |  |  |  |
| `risk.quantification.reports.capital-adequacy` | `risk/quantification/reports/capital-adequacy` | `quantification.view` |  |  |  |  |  |  |  |  |  |  |
| `risk.quantification.reports.regulatory-pack` | `risk/quantification/reports/regulatory-pack` | `quantification.view` |  |  |  |  |  |  |  |  |  |  |
| `risk.quantification.reports.risk-contribution` | `risk/quantification/reports/risk-contribution` | `quantification.view` |  |  |  |  |  |  |  |  |  |  |
| `risk.quantification.reports.stress-testing` | `risk/quantification/reports/stress-testing` | `quantification.view` |  |  |  |  |  |  |  |  |  |  |
| `risk.quantification.results` | `risk/quantification/results` | `quantification.view` |  |  |  |  |  |  |  |  |  |  |
| `risk.quantification.scenarios` | `risk/quantification/scenarios` | `quantification.view` |  |  |  |  |  |  |  |  |  |  |
| `risk.quantification.settings` | `risk/quantification/settings` | `quantification.view` |  |  |  |  |  |  |  |  |  |  |
| `risk.quantification.show-results` | `risk/quantification/results/{simulation}` | `quantification.view` |  |  |  |  |  |  |  |  |  |  |
| `risk.quantification.show-scenario` | `risk/quantification/scenarios/{scenario}` | `quantification.view` |  |  |  |  |  |  |  |  |  |  |
| `risk.quantification.simulate` | `risk/quantification/simulate` | `quantification.view` |  |  |  |  |  |  |  |  |  |  |
| `risk.questionnaires.create` | `risk/questionnaires/create` | `questionnaire.create` | risk/questionnaires/create.blade.php (deleted) | Pages/Questionnaires/Create.jsx | StoreQuestionnaireRequest | QuestionnairePolicy |  |  |  | Campaigns/* | 2026-09-05 (Phase 4.5) |  |
| `risk.questionnaires.edit` | `risk/questionnaires/{questionnaire}/edit` | `questionnaire.edit` | risk/questionnaires/edit.blade.php (deleted) | Pages/Questionnaires/Edit.jsx | AddSectionRequest, AddQuestionRequest | QuestionnairePolicy |  |  |  | Campaigns/*, UpgradeEndToEndTest | 2026-09-05 (Phase 4.5) |  |
| `risk.questionnaires.index` | `risk/questionnaires` | `questionnaire.view` | risk/questionnaires/index.blade.php (deleted) | Pages/Questionnaires/Index.jsx |  |  | GridPresenter |  | risk.grids.export | Grid/* | 2026-09-03 (Phase 2) |  |
| `risk.questionnaires.library` | `risk/question-library` | `questionnaire.view` | risk/questionnaires/library.blade.php (deleted) | Pages/Questionnaires/Library.jsx |  |  | GridPresenter |  | risk.grids.export | Grid/* | 2026-09-03 (Phase 2) |  |
| `risk.questionnaires.show` | `risk/questionnaires/{questionnaire}` | `questionnaire.view` | risk/questionnaires/show.blade.php (deleted) | Pages/Questionnaires/Show.jsx | — | QuestionnairePolicy |  |  |  | Campaigns/* | 2026-09-05 (Phase 4.5) |  |
| `risk.rcsa.controls` | `risk/rcsa/controls` | `rcsa.view` | risk/rcsa/controls.blade.php (deleted) | Pages/Rcsa/Controls.jsx |  | RcsaPolicy |  |  |  | Rcsa/RcsaPagesTest, RcsaPolicyTest, WorksheetFilingTest, Characterisation/RcsaFiguresTest | 2026-09-04 (Phase 3.8) |  |
| `risk.rcsa.dashboard` | `risk/rcsa/dashboard` | `rcsa.view` | risk/rcsa/dashboard.blade.php (deleted) | Pages/Rcsa/Dashboard.jsx |  | RcsaPolicy |  | house SVG/CSS charts (see notes) |  | Rcsa/RcsaPagesTest, RcsaPolicyTest, WorksheetFilingTest, Characterisation/RcsaFiguresTest | 2026-09-04 (Phase 3.8) |  |
| `risk.rcsa.matrix` | `risk/rcsa/matrix` | `rcsa.view` | risk/rcsa/matrix.blade.php (deleted) | Pages/Rcsa/Matrix.jsx |  | RcsaPolicy |  |  | risk.export.rcsa-matrix | Rcsa/RcsaPagesTest, RcsaPolicyTest, WorksheetFilingTest, Characterisation/RcsaFiguresTest | 2026-09-04 (Phase 3.8) |  |
| `risk.rcsa.worksheet` | `risk/rcsa/worksheet` | `rcsa.view` | risk/rcsa/worksheet.blade.php (deleted) | Pages/Rcsa/Worksheet.jsx | SubmitRcsaWorksheetRequest | RcsaPolicy |  |  |  | Rcsa/RcsaPagesTest, RcsaPolicyTest, WorksheetFilingTest, Characterisation/RcsaFiguresTest | 2026-09-04 (Phase 3.8) |  |
| `risk.register.create` | `risk/register/create` | `risk.create` | risk/register/create.blade.php (deleted) | Pages/Register/Create.jsx | StoreRiskRequest | RiskPolicy | n/a | n/a | n/a | Register/RiskPagesTest, RiskRequestsTest, Characterisation/RiskRegisterScoringTest | 2026-09-04 (Phase 3.2) |  |
| `risk.register.edit` | `risk/register/{register}/edit` | `risk.edit` | risk/register/edit.blade.php (deleted) | Pages/Register/Edit.jsx | UpdateRiskRequest | RiskPolicy | n/a | n/a | n/a | Register/RiskPagesTest, RiskRequestsTest | 2026-09-04 (Phase 3.2) |  |
| `risk.register.index` | `risk/register` | `risk.view` | risk/register/{index,historic}.blade.php (both deleted) | Pages/Register/Index.jsx; Pages/Register/Historic.jsx (as-at branch) |  | RiskPolicy | GridPresenter |  | risk.grids.export | Grid/RisksGridTest, Measures/PeriodSelectorTest | 2026-09-03 (Phase 2), as-at 2026-09-04 (Phase 3.2) |  |
| `risk.register.show` | `risk/register/{register}` | `risk.view` | risk/register/show.blade.php (deleted) | Pages/Register/Show.jsx | MapControlRequest, UpdateRiskAttributesRequest | RiskPolicy | n/a | n/a | n/a | Register/RiskPagesTest, RiskPolicyTest, Characterisation/RiskRegisterScoringTest, Metadata/DynamicDetailIntegrationTest | 2026-09-04 (Phase 3.2) |  |
| `risk.regulatory.calendar` | `risk/regulatory/calendar` | `regulatory.view` |  |  |  |  |  |  |  |  |  |  |
| `risk.regulatory.circulars` | `risk/regulatory/circulars` | `regulatory.view` | risk/regulatory/circulars.blade.php (deleted) | Pages/Regulatory/Circulars.jsx |  |  | GridPresenter |  | risk.grids.export | Grid/* | 2026-09-03 (Phase 2) |  |
| `risk.regulatory.create-circular` | `risk/regulatory/circulars/create` | `regulatory.manage` |  |  |  |  |  |  |  |  |  |  |
| `risk.regulatory.create-deadline` | `risk/regulatory/deadlines/create` | `regulatory.manage` |  |  |  |  |  |  |  |  |  |  |
| `risk.regulatory.dashboard` | `risk/regulatory/dashboard` | `regulatory.view` |  |  |  |  |  |  |  |  |  |  |
| `risk.regulatory.deadlines` | `risk/regulatory/deadlines` | `regulatory.view` |  |  |  |  |  |  |  |  |  |  |
| `risk.regulatory.show-circular` | `risk/regulatory/circulars/{circular}` | `regulatory.view` |  |  |  |  |  |  |  |  |  |  |
| `risk.regulatory.taxonomy` | `risk/regulatory/taxonomy` | `regulatory.view` |  |  |  |  |  |  |  |  |  |  |
| `risk.reports.board` | `risk/reports/board` | `report.view` |  |  |  |  |  |  |  |  |  |  |
| `risk.reports.board-pack.sections` | `risk/reports/board-pack/sections` | `report.view` |  |  |  |  |  |  |  |  |  |  |
| `risk.reports.custom` | `risk/reports/custom` | `report.view` |  |  |  |  |  |  |  |  |  |  |
| `risk.reports.custom.generate` | `risk/reports/custom/generate` | `report.generate` |  |  |  |  |  |  |  |  |  |  |
| `risk.reports.download` | `risk/reports/{report}/download` | `report.view` |  |  |  |  |  |  |  |  |  |  |
| `risk.reports.executive` | `risk/reports/executive` | `report.view` |  |  |  |  |  |  |  |  |  |  |
| `risk.reports.library` | `risk/reports/library` | `report.view` | risk/reports/library.blade.php (deleted) | Pages/Reports/Library.jsx |  |  | GridPresenter |  | risk.grids.export | Grid/* | 2026-09-03 (Phase 2) |  |
| `risk.reports.regulatory` | `risk/reports/regulatory` | `report.view` |  |  |  |  |  |  |  |  |  |  |
| `risk.reports.status` | `risk/reports/{report}/status` | `report.view` |  |  |  |  |  |  |  |  |  |  |
| `risk.reports.status-json` | `risk/reports/{report}/status.json` | `report.view` |  |  |  |  |  |  |  |  |  |  |
| `risk.scoping.create` | `risk/scoping/create` | `entity.create` | risk/scoping/create.blade.php (deleted) | Pages/Scoping/Create.jsx (+ EntityForm.jsx) | Scoping/StoreEntityRequest | EntityPolicy |  | DynamicForm (FormSchemaPresenter) | n/a | Scoping/EntityPagesTest, Scoping/EntityRequestsTest, Scoping/EntityPolicyTest | 2026-09-03 (Phase 3) |  |
| `risk.scoping.dashboard` | `risk/scoping/dashboard` | `entity.view` | risk/scoping/dashboard.blade.php, risk/scoping/_tree-node.blade.php (deleted) | Pages/Scoping/Dashboard.jsx (+ Components/EntityTree.jsx) | n/a | EntityPolicy |  | KpiCard, DonutChart (props from EntityService) | n/a | Scoping/EntityPagesTest, Characterisation/EntityRiskScoreTest | 2026-09-03 (Phase 3) |  |
| `risk.scoping.edit` | `risk/scoping/{scoping}/edit` | `entity.edit` | risk/scoping/edit.blade.php (deleted) | Pages/Scoping/Edit.jsx (+ EntityForm.jsx) | Scoping/UpdateEntityRequest | EntityPolicy |  | DynamicForm (FormSchemaPresenter) | n/a | Scoping/EntityPagesTest, Scoping/EntityRequestsTest, Scoping/EntityPolicyTest | 2026-09-03 (Phase 3) |  |
| `risk.scoping.index` | `risk/scoping` | `entity.view` | risk/scoping/index.blade.php (deleted) | Pages/Scoping/Index.jsx |  |  | GridPresenter |  | risk.grids.export | Inertia/ScopingIndexTest, Grid/* | 2026-09-03 (Phase 2) |  |
| `risk.scoping.show` | `risk/scoping/{scoping}` | `entity.view` | risk/scoping/show.blade.php (deleted) | Pages/Scoping/Show.jsx | n/a | EntityPolicy |  | KpiCard, DonutChart, DynamicDetail (FormSchemaPresenter) | n/a | Scoping/EntityPagesTest, Scoping/EntityPolicyTest, Characterisation/EntityRiskScoreTest | 2026-09-03 (Phase 3) |  |
| `risk.thresholds.rebaseline` | `risk/thresholds/rebaseline` | `threshold.view` | risk/thresholds/rebaseline.blade.php (deleted) | Pages/Thresholds/Rebaseline.jsx | Approve/RejectRebaselineRequest | MeasureThresholdPolicy |  |  |  | Periods/PeriodAndThresholdPagesTest | 2026-09-04 (Phase 4.2) |  |
| `risk.treatments.create` | `risk/treatments/create` | `treatment.create` | risk/treatments/create.blade.php (deleted) | Pages/Treatments/Create.jsx | StoreTreatmentPlanRequest | TreatmentPlanPolicy |  |  |  | Treatments/TreatmentPagesTest, TreatmentPoliciesTest, Characterisation/TreatmentDashboardStatsTest | 2026-09-04 (Phase 3.5) |  |
| `risk.treatments.dashboard` | `risk/treatments/dashboard` | `treatment.view` | risk/treatments/dashboard.blade.php (deleted) | Pages/Treatments/Dashboard.jsx |  | TreatmentPlanPolicy |  | house SVG/CSS charts (see notes) |  | Treatments/TreatmentPagesTest, TreatmentPoliciesTest, Characterisation/TreatmentDashboardStatsTest | 2026-09-04 (Phase 3.5) |  |
| `risk.treatments.edit` | `risk/treatments/{treatment}/edit` | `treatment.edit` | risk/treatments/edit.blade.php (deleted) | Pages/Treatments/Edit.jsx | UpdateTreatmentPlanRequest | TreatmentPlanPolicy |  |  |  | Treatments/TreatmentPagesTest, TreatmentPoliciesTest, Characterisation/TreatmentDashboardStatsTest | 2026-09-04 (Phase 3.5) |  |
| `risk.treatments.index` | `risk/treatments` | `treatment.view` | risk/treatments/index.blade.php (deleted) | Pages/Treatments/Index.jsx |  |  | GridPresenter |  | risk.grids.export | Grid/* | 2026-09-03 (Phase 2) |  |
| `risk.treatments.review` | `risk/treatments/review` | `treatment.approve` | risk/treatments/review.blade.php (deleted) | Pages/Treatments/Review.jsx | ApproveTreatmentPlanRequest, RejectTreatmentPlanRequest, StoreTreatmentCommentRequest | TreatmentPlanPolicy |  |  |  | Treatments/TreatmentPagesTest, TreatmentPoliciesTest, Characterisation/TreatmentDashboardStatsTest | 2026-09-04 (Phase 3.5) |  |
| `risk.treatments.show` | `risk/treatments/{treatment}` | `treatment.view` | risk/treatments/show.blade.php (deleted) | Pages/Treatments/Show.jsx | ApproveTreatmentPlanRequest, RejectTreatmentPlanRequest | TreatmentPlanPolicy |  |  |  | Treatments/TreatmentPagesTest, TreatmentPoliciesTest, Characterisation/TreatmentDashboardStatsTest | 2026-09-04 (Phase 3.5) |  |
| `risk.workflows.create-definition` | `risk/workflows/definitions/create` | `workflow.manage` |  |  |  |  |  |  |  |  |  |  |
| `risk.workflows.dashboard` | `risk/workflows/dashboard` | `workflow.view` | risk/workflows/dashboard.blade.php (deleted) | Pages/Workflows/Dashboard.jsx |  | WorkflowInstancePolicy |  |  |  | Workflow/WorkflowPagesTest, Workflow/WorkflowScreensTest, Characterisation/WorkflowDashboardStatsTest | 2026-09-03 (Phase 3) |  |
| `risk.workflows.definitions` | `risk/workflows/definitions` | `workflow.view` | risk/workflows/definitions.blade.php (deleted) | Pages/Workflows/Definitions.jsx | Workflow/{StartWorkflowRequest,StoreWorkflowDefinitionRequest} | WorkflowDefinitionPolicy |  |  |  | Workflow/WorkflowPagesTest, Workflow/WorkflowScreensTest | 2026-09-03 (Phase 3) |  |
| `risk.workflows.edit-definition` | `risk/workflows/definitions/{definition}/design` | `workflow.manage` |  |  |  |  |  |  |  |  |  |  |
| `risk.workflows.show-instance` | `risk/workflows/{instance}` | `workflow.view` | risk/workflows/show-instance.blade.php (deleted) | Pages/Workflows/ShowInstance.jsx | Workflow/ActOnInstanceRequest | WorkflowInstancePolicy |  |  |  | Workflow/WorkflowPagesTest, Workflow/WorkflowScreensTest | 2026-09-03 (Phase 3) |  |
| `search.index` | `search` | `search.view` | search/index.blade.php (deleted) | Pages/Search/Index.jsx |  |  |  |  |  | Inertia/AuthPagesTest, Widgets/HqSurfacesTest | 2026-09-03 (Phase 1) |  |
| `search.suggest` | `search/suggest` | `search.view` |  |  |  |  |  |  |  |  |  |  |
| `sso.callback` | `auth/sso/{slug}/callback` | `` |  |  |  |  |  |  |  |  |  |  |
| `sso.metadata` | `auth/sso/{slug}/metadata` | `` |  |  |  |  |  |  |  |  |  |  |
| `sso.redirect` | `auth/sso/{slug}` | `` |  |  |  |  |  |  |  |  |  |  |
| `profile.edit` | `profile` | `dashboard.view` | — (new) | Pages/Profile/Edit.jsx | ProfileUpdateRequest |  |  |  |  | Inertia/AuthPagesTest | 2026-09-03 (Phase 1) |  |
| `notifications.unread-count` | `notifications/unread-count` | `notification.view` | — (new, JSON) | n/a |  |  |  |  |  | Inertia/AuthPagesTest | 2026-09-03 (Phase 1) |  |
| `risk.grids.show` | `risk/grids/{grid}` | `view-grid gate` | — (new, JSON) | n/a |  |  |  |  |  | Grid/*, Widgets/WidgetPayloadEndpointTest, Inertia/JobProgressTest | 2026-09-03 (Phase 2) |  |
| `risk.grids.export` | `risk/grids/{grid}/export/{format}` | `view-grid gate` | — (new, file) | n/a |  |  |  |  |  | Grid/*, Widgets/WidgetPayloadEndpointTest, Inertia/JobProgressTest | 2026-09-03 (Phase 2) |  |
| `risk.widgets.payload` | `risk/widgets/{widget}/payload` | `dashboard.view` | — (new, JSON) | n/a |  |  |  |  |  | Grid/*, Widgets/WidgetPayloadEndpointTest, Inertia/JobProgressTest | 2026-09-03 (Phase 2) |  |
| `risk.widgets.export` | `risk/widgets/{widget}/export` | `dashboard.view` | — (new, file) | n/a |  |  |  |  |  | Grid/*, Widgets/WidgetPayloadEndpointTest, Inertia/JobProgressTest | 2026-09-03 (Phase 2) |  |
| `risk.jobs.progress` | `risk/jobs/{jobRun}/progress` | `job.view` | — (new, JSON) | n/a |  |  |  |  |  | Grid/*, Widgets/WidgetPayloadEndpointTest, Inertia/JobProgressTest | 2026-09-03 (Phase 2) |  |
