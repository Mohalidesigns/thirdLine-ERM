# 02 — Current-state assessment: the risk product (`risk copy/`)

Audited 2 Sep 2026 against `erm-remodel/wp00-wp06` @ `5fe424a` (`Wave 3 — close the loop`). Read-only. Paths are relative to `risk copy/`.

**Correction to the brief.** The brief describes the current frontend as "plain HTML". It is not: the risk product is a Laravel 12 application whose presentation layer is **server-rendered Blade (211 views) + Livewire 3 (11 components) + Alpine (95 `x-data` islands) + Chart.js (54 inline chart constructions)**, built with Vite 7 and Tailwind 4. The backend is, in several respects, *more* mature than ThirdLine's (tenancy kernel, 118 route-level permissions enforced by a regression test, a 1,300-line workflow engine, a scoped REST API + MCP server, ~950 tests). The migration is therefore a **presentation-layer and conventions migration onto a backend that stays**, not a rebuild. That reframing drives everything in `04-migration-strategy.md`.

---

## 1. Stack and entry points

| Concern | Current | Evidence |
|---|---|---|
| Framework | Laravel `^12.0`, PHP `^8.2` | `composer.json` |
| UI | Blade + `livewire/livewire ^3.0` (Alpine from Livewire's ESM bundle) + `chart.js ^4.5.1` + `gridstack ^13.2.0` | `resources/js/app.js:3-4`, `package.json` |
| Build | Vite `^7.0.7`, `laravel-vite-plugin ^2`, `@tailwindcss/vite ^4`, `tailwindcss ^4` | `vite.config.js` (inputs `resources/css/app.css`, `resources/js/app.js`) |
| RBAC | `spatie/laravel-permission ^7.2` + custom `CheckPermission` middleware | `app/Http/Middleware/CheckPermission.php` (fails closed on unknown permission) |
| Tokens/API | `laravel/sanctum ^4.3` (extended `personal_access_tokens`), `dedoc/scramble` OpenAPI at `/api/docs` | `app/Models/ApiToken.php`, `config/scramble.php` |
| Queue | `laravel/horizon ^5.48`; 7 jobs | `app/Jobs/*`, `app/Providers/HorizonServiceProvider.php` |
| SSO | `laravel/socialite ^5.29` (OIDC), `onelogin/php-saml ^4.3` (SAML) | `app/Support/Sso/{OidcProvider,SamlDriver}.php`, `app/Http/Controllers/Auth/SsoController.php` |
| PDF / XLSX | `barryvdh/laravel-dompdf ^3.1`, `maatwebsite/excel ^3.1` | `app/Services/DocumentRenderer.php`, `app/Grids/GridExport.php` |
| Formulas | `symfony/expression-language ^8.1` | `app/Services/FormulaEvaluator.php`, `app/Services/Workflow/ConditionEvaluator.php` |
| AI | Ollama-compatible client, off by default | `app/Services/LlmService.php`, `config/services.php` `llm` block, `config/features.php` |
| Tests | PHPUnit 11 on **in-memory SQLite** | `phpunit.xml` |
| Static analysis | none (Pint only) | `composer.json` require-dev |

**Bootstrap** (`bootstrap/app.php`): API prefix is `''` so SCIM lives at `/scim/v2`; middleware aliases `auth => EnsureAuthenticated`, `permission => CheckPermission`, `mfa`, `tenant => ResolveTenant`, `scim.auth`, `feature => EnsureFeatureEnabled`, `api.auth`, `scope`, `scope.resource`, `idempotency` (L20-36). `SubstituteBindings` is re-ordered to run **after** `ResolveTenant` and `ResolvePeriod` so route-model binding is tenant-scoped (L46-53, L74-77). `withExceptions` is empty.

**Providers**: `AppServiceProvider` (310 lines) binds `TenantContext`/`PeriodContext` singletons, forces `livewire.inject_assets=false`, enforces the morph map (`app/Support/MorphTypes.php`), registers `WorkflowTriggerObserver` and `WebhookEventObserver` on every registered model, re-registers Livewire's update route under `['web','auth','permission:dashboard.view']` (L88-90), runs a View composer with two raw `notifications_log` queries per page for the bell (L93-110), defines `Gate::before` super-admin bypass and six inline `Gate::define` closures with hard-coded role names (L116-193). `EventServiceProvider` disables discovery because listeners once ran twice (documented L11-40).

**Custom config** (11 files): `authorization.php` (full-org roles, subtree visibility), `connectors.php`, `features.php` (`ai_intelligence`, `mfa_totp` — both default off), `measures.php`, `quantification.php` (CBN CAR minima), `risk.php` (control-effectiveness bands, impact aggregation), `sso.php`, `webhooks.php` (SSRF guard, HMAC), `workflow.php` (subject bindings), `cors.php`, `services.php` (`llm`). Env keys read but absent from `.env.example`: `FEATURE_MFA_TOTP`, `MEASURE_*`, `CBN_RATE_*`, `WEBHOOKS_*`, `WORKFLOW_*`, `CONNECTORS_DRY_RUN_FIRST`, `SSO_ENTRA_*_URL`, `SSO_GOOGLE_GROUPS_CLAIM`.

---

## 2. How the frontend talks to the backend

### 2.1 Blade — 211 views

```
resources/views/
├── admin/ (19)        api-tokens, builder (6), configuration, connectors (2), jobs, settings (2), users (4), webhooks (2)
├── auth/ (6)          login, register, forgot/reset password, mfa-setup, mfa-verify
├── components/ (9)    data-grid, data-grid-cell, data-table, dynamic-attributes, dynamic-detail, dynamic-form, kpi-card, risk-badge, status-badge
├── hq/ (3)            show, empty, partials/tree
├── layouts/ (5)       app + partials/{branding, period-selector, sidebar (599 lines), topbar}
├── livewire/ (12)     data-grid, dynamic-form, job-progress, admin/* builders (7), widgets/* (2)
├── reports/pdf/ (15)  executive, regulatory, risk-register, board-pack, layout, sections/* (10)   ← KEEP (server-side PDF)
├── risk/ (128)        dashboard, ai (3), analysis (4), appetite, approvals (2), assessments (4), campaigns (6), controls (9),
│                      dashboards (2), documents, emerging (4), imports (3), issues (7), kri (7), loss-events (10), my-tasks (2),
│                      periods, quantification (15), questionnaires (5), rcsa (4), register (4), regulatory (9), reports (7),
│                      scoping (6), thresholds, treatments (6), workflows (4)
├── widgets/types/ (6) activity-table, heatmap, kpi-tile, measure-table, opportunity-heatmap, register
├── emails/ (1), my/ (1), notifications/ (1), search/ (1), vendor/pagination (4)
```

### 2.2 Livewire — 11 components (`app/Livewire/`)

| Component | Lines | Role | Migration note |
|---|---|---|---|
| `DataGrid.php` | 542 | **The** shared grid: search, sort, filters, saved views (`data_grid_views`), bulk actions, inline edit, CSV/XLSX export, driven by 22 `app/Grids/Definitions/*Grid.php` over `app/Grids/GridDefinition.php` | Server-side `GridDefinition` stays; the Livewire component becomes a React `DataGrid` fed by a `GridPresenter` |
| `DynamicForm.php` | 317 | Renders/validates/persists tenant-configured `object_attributes` → `objects.attributes`; raw insert to `object_versions` (L183) | Becomes React `DynamicForm` + a `ConfiguredAttributesRequest` rule set |
| `Admin/{ObjectTypeBuilder, AttributeBuilder, RelationshipTypeBuilder, LifecycleBuilder, ScoringProfileBuilder, WorkflowDesigner}.php` | 306/437/251/248/436/419 | Metadata builders (WP-05/06) | Rebuild as React pages over new JSON endpoints or Inertia partial reloads |
| `Widgets/DashboardBuilder.php`, `Widgets/WidgetPanel.php` | 477/152 | GridStack layout editor; per-panel payload resolution | React `DashboardBuilder` (GridStack) + `Widget` component; payload resolution stays in `WidgetDataService` |
| `JobProgress.php` | 88 | Polls `job_runs` | React `useJobProgress` hook polling `api/v1/jobs/{jobRun}` |

Directive counts: `wire:model` 122, `wire:click` 78, `wire:submit` 5; every sidebar link uses `wire:navigate`, which is why `app.js` carries `destroyDetachedCharts()` and `layouts/app.blade.php:23-98` carries `wrapSizedCanvases` DOM surgery.

### 2.3 JavaScript — 1,511 lines

`resources/js/app.js` (154): Livewire+Alpine start, `Chart` global, page-ready shim, dynamic import of `widgets/index.js` / `widgets/builder.js` when `[data-widget]`/`[data-dashboard-builder]` present, chart teardown on navigate, live-search auto-submit (plain form submit, not AJAX, L124-153). `widgets/charts.js` (904): 14 Chart.js renderers exported as `chartRenderers`. `widgets/index.js` (259): payload envelope parser + DOM `treemap`/`network` renderers + re-hydration on `livewire:navigated`/MutationObserver/theme change. `widgets/theme.js` (115): `--viz-*` token reader, `fmtNaira`, `fmtNum`. `widgets/builder.js` (75): GridStack ↔ `$wire.updateLayout`.

Inline `<script>` in 47 Blade files; `new Chart(` in 27 files (54 instances) receiving data via `@json()` with per-view hard-coded palettes (`risk/dashboard.blade.php:545-550`). `fetch()` ×6 (search suggest, four `risk.ai.tools.*` helpers, report status polling); `axios` configured but unused.

### 2.4 Forms

143 traditional `<form method="POST">` in 83 files → controller `$request->validate()` (112 inline calls) → redirect with flash. This maps 1:1 onto Inertia `useForm` + `post(route(...))` + Form Request, which is why the port is mechanical for ~70 % of screens.

### 2.5 Business logic that lives in JS (must move server-side during the port)

1. **`resources/views/risk/assessments/create.blade.php` L675-875** — Alpine `assessmentChain()` re-implements impact aggregation (max/average/worst_two/weighted), inherent score, per-control effectiveness = min(design, operating), the ≥20-point design/operating divergence finding, weighted aggregate, and the residual split `target = max(1, round(inherent*(1-overall/100)))` clamped 0.15..0.85 — a hand-mirror of `app/Services/RiskScoringService.php` and `app/Services/AssessmentChainService.php::deriveResidual()`. Its own docblock (L676-683) says the server recomputes on save; a tenant-configured residual formula is *not* previewed (L813-815). **Target:** one server-side preview endpoint (`POST risk/assessments/preview` returning the chain), or, if latency demands a client mirror, a `tests/js/mirror-parity.mjs`-style gate exactly as ThirdLine does for `ReferenceRowValidator`.
2. `risk/dashboard.blade.php` L538-891 — seven Chart.js constructions with formatting logic (₦ B/M/K ticks, percent tooltips). **Target:** widget payloads from `WidgetDataService` + React chart components.
3. `layouts/app.blade.php` L44-97 — canvas sizing workaround for Chart.js under `wire:navigate`. **Disappears** with React lifecycle.

Everything else in JS is presentation (treemap/network layout, theme tokens).

---

## 3. Routes

| File | Lines | Declarations | Named |
|---|---|---|---|
| `routes/web.php` | 1,183 | 342 | 339 |
| `routes/api.php` | 200 | 21 (+9 SCIM) | 13 |
| `routes/console.php` | 50 | 16 `Schedule::command` | — |

`web.php` L204-217 states the invariant "**every route below carries a `permission:` guard**", enforced by `tests/Feature/RouteAuthorizationTest.php` (fails the build on any unguarded web/api route) and by `app:preflight` (`tests/Feature/PreflightRouteGuardTest.php`). Groups: public auth (`throttle:login|password-reset|mfa-verify|sso-discover`); `auth` group (= `EnsureAuthenticated` + `EnsureMfaVerified`, `AppServiceProvider:73`); `admin/*` (L411, nested `permission:admin.users|admin.settings|admin.sso|admin.metadata|admin.scoring|admin.configuration|webhook.*|api.tokens|connector.*|job.view`); `risk/*` (L527-1183, per-route `permission:`; sub-groups `analysis.view`, `['permission:ai.view','feature:ai_intelligence']`, `report.export`).

Route-name families (count): `admin.{users 9, settings 7, builder 6, configuration 6, connectors 7, webhooks 8, api-tokens 3, jobs 2}`; `risk.{dashboard 1, dashboards 3, scoping 8, register 8, rcsa 5, assessments 10, controls 9, control-tests 13, treatments 14, appetite 3, approvals 4, kri 14, periods 4, thresholds 3, loss-events 23, issues 19, campaigns 12, questionnaires 11, my-tasks 5, workflows 10, analysis 4, quantification 22, regulatory 13, imports 4, documents 1, reports 12, emerging 7, ai 11, export 16}`; platform `my, hq.{index,show}, notifications.*, search.*`.

API (`routes/api.php`): `scim/v2/{Users,Groups}` (`throttle:scim` + `scim.auth`); `api/v1` (`api.auth`, `throttle:api-token`, `idempotency`): catalogue, `me`, `graph/{object}/{descendants,ancestors,related}`, `measures/{measure}/series`, `jobs/{jobRun}`, generic `{resource}` CRUD over `app/Http/Api/ApiResourceRegistry.php` (22 resources, no DELETE), `POST mcp`.

Controllers: 47 in `app/Http/Controllers/` (+2 concerns) and 5 in `app/Http/Api/`… see the full table with line counts in the appendix of the audit; the ones that matter for effort are `Risk/QuantificationController` **1,611**, `Risk/ReportController` **1,078**, `Risk/LossEventController` 969, `Risk/AnalysisController` 851, `Risk/IssueController` 817, `Risk/RiskAssessmentController` 801, `Risk/ExportController` 766, `Auth/AuthController` 680, `Risk/AiToolsController` 672, `Risk/KriController` 627, `Risk/TreatmentPlanController` 603.

---

## 4. Data model

122 migrations; 105 `Schema::create` tables (+5 Spatie). Two strata: the **Feb–Apr 2026 domain core** and the **Aug 2026 platform wave** (WP-00→WP-13).

| Module | Tables (key FKs) | Models |
|---|---|---|
| Platform / auth | `users` (+`organization_id`, `scope_entity_id`, mfa_*), Spatie ×5, `personal_access_tokens` (+org, token_type, rate_limit_per_minute), `api_idempotency_keys`, `scim_tokens`, `organization_sso_settings`, `organizations` (settings JSON), `job_runs`, `notifications_log`, `notification_preferences`, `notification_templates`, `domain_events`, `reference_sequences` | `User, ApiToken, ScimToken, OrganizationSsoSetting, Organization, JobRun, DomainEvent` |
| Org graph — legacy | `business_units`, `business_processes`, `entity_types`, `entities` (`hierarchy_path`) | `BusinessUnit, BusinessProcess, EntityType, Entity` |
| Org graph — unified (WP-03) | `object_types`, `object_attributes`, `object_lifecycles`, `objects` (`organization_id, object_type_id, node_id→objects, parent_id→objects, attributes JSON, lifecycle_state, hierarchy_path`; unique `[org,type,code]`), `object_versions`, `object_relationship_types`, `object_relationships` (unique edge), `object_merge_candidates` | `ObjectType, ObjectAttribute, ObjectLifecycle, GraphObject, ObjectVersion, ObjectRelationshipType, ObjectRelationship, ObjectMergeCandidate` |
| Risk register & assessment | `risk_categories`, `risk_taxonomies`, `risks` (inherent/residual L·I, 4+1 impact dims, `entity_id`, `node_id`, acceptance), `risk_assessments`, `risk_assessment_controls`, `risk_causes`, `risk_cause_categories`, `risk_control_mapping`, `risk_kri_mapping`, `risk_related_risks`, `risk_audit_trail` (hash chain), `risk_appetite`, `emerging_risks`, `approval_requests` (legacy) | `Risk, RiskAssessment, RiskAssessmentControl, RiskCause, RiskCauseCategory, RiskControlMapping (pivot, projects edge), RiskKriMapping, RiskAuditTrail, RiskAppetite, RiskCategory, RiskTaxonomy, EmergingRisk, ApprovalRequest` |
| Controls | `controls`, `control_tests`, `control_test_evidence` | `Control, ControlTest, ControlTestEvidence` |
| Treatments / issues | `treatment_plans`; `issues`, `issue_remediation_actions`, `issue_progress_updates`, `issue_attachments`, `issue_escalation_rules`, `issue_escalation_log` | `TreatmentPlan, Issue, Issue*` |
| KRI & measure engine (WP-04) | `key_risk_indicators`, `kri_measurements` (legacy), `units`, `period_calendars`, `periods`, `measures`, `measure_values` (unique measure×object×period), `measure_thresholds` (effective-dated), `measure_breaches`, `fx_rates` | `KeyRiskIndicator, KriMeasurement, Unit, PeriodCalendar, Period, Measure, MeasureValue, MeasureThreshold, MeasureBreach, FxRate` |
| Loss events | `loss_events`, `loss_event_controls`, `loss_event_attachments`, `loss_event_rca`, `rca_remediation_actions`, `near_misses`, `loss_event_approvals` | `LossEvent, LossEventControl (edge), LossEventAttachment, LossEventRca, RcaRemediationAction, NearMiss, LossEventApproval` |
| Quantification | `quantification_scenarios` (+seed), `simulation_runs` (+seed), `simulation_results` (+ES), `icaap_assessments`, `quantification_settings` | `QuantificationScenario, SimulationRun, SimulationResult, IcaapAssessment, QuantificationSetting` |
| Campaigns / questionnaires | `assessment_campaigns`, `campaign_assignments`, `campaign_responses`, `questionnaires`, `questionnaire_sections`, `questions`, `question_library` | `AssessmentCampaign, CampaignAssignment, CampaignResponse, Questionnaire, QuestionnaireSection, Question, QuestionLibrary` |
| Regulatory | `regulatory_risk_mapping`, `regulatory_circulars`, `regulatory_deadlines`, `regulatory_filings` | `RegulatoryCircular, RegulatoryDeadline, RegulatoryFiling` |
| Workflow v2 (WP-06) | `workflow_definitions` (definition JSON), `workflow_instances`, `workflow_actions`, `workflow_tasks` | `WorkflowDefinition, WorkflowInstance, WorkflowAction, WorkflowTask` |
| Configuration (WP-05) | `scoring_profiles`, `config_bundles`, `config_bundle_applications` | `ScoringProfile, ConfigBundle, ConfigBundleApplication` |
| Dashboards / grids / reports / integrations | `widget_definitions`, `dashboards` (org-nullable, `published_layout`), `dashboard_user_prefs`, `data_grid_views`, `generated_reports`, `data_imports`, `webhook_subscriptions`, `webhook_deliveries`, `connectors`, `connector_runs` | `WidgetDefinition, Dashboard, DashboardUserPref, DataGridView, GeneratedReport, DataImport, WebhookSubscription, WebhookDelivery, Connector, ConnectorRun` |

**Traits** (`app/Models/Concerns/`): `BelongsToOrganization` (global `OrganizationScope` + auto-stamp; 69 of 92 models), `HasObjectIdentity` (mirror into `objects` via `ObjectSyncService`; "sync never fails a save"; 15 models), `ProjectsGraphEdge` (pivot row → `object_relationships` via `PivotEdgeProjector`), `ScopedToGraph` (`visibleTo($user)` + tenant/node-safe route binding that 404s outside the caller's subtree). Morph map enforced (`app/Support/MorphTypes.php`, ~45 aliases).

**Tenancy kernel** (`app/Support/Tenancy/`): `TenantContext` **throws** when unresolved (L70-76 — "silently defaulting to organization 1 is precisely the bug this class prevents"), `bypass()`/`actingAs()` are logged; `OrganizationScope` skips filtering only when no tenant is bound (console). `ResolveTenant` binds from `auth()->user()->organization_id`; `AuthenticateApiToken` / `AuthenticateScim` bind from tokens. Tested by `tests/Feature/TenancyIsolationTest.php` (11 tests).

**Data volumes**: not visible — only a 937 KB SQLite file `risk` at the repo root and seeders (`DemoDataSeeder`, `WidgetDashboardSeeder` 886 lines). `phpunit.xml` mentions a 5,000-risk register perf test (WP-04).

---

## 5. Functional inventory

### 5.1 Navigation (`resources/views/layouts/partials/sidebar.blade.php`, 599 lines)

Fixed: Command Centre (ungated), `@can('my.view')` My Responsibilities, `@can('hq.view')` Business HQ, `@can('dashboard.manage')` Dashboards. Then **22 sections with no permission gate on sections or items** (`$navSections` L40-343 has zero `permission` keys — a `board-member` sees "Create New Risk" links that 403). Administration (L457-575) *is* gated with `@canany` + per-link `@can`, the pattern `tests/Feature/AdminNavigationTest.php` enforces.

Sections (ISO 31000 order): Scoping · Risk Register · RCSA · Risk Assessments · Control Library (+ testing) · Treatment Plans · Risk Appetite · Approvals · KRI Monitoring · Reporting Periods · Loss Events · Issues & Findings · Campaigns · Workflows · Risk Analysis · Risk Quantification · Regulatory · Data Import · Documents · Reports · Emerging Risk · (flag) Risk Intelligence · Administration {Access, Configuration, Integration}.

### 5.2 Screens, forms, workflows, reports (by module)

| Module | Screens | Forms / workflows | Grid def | Reports / exports |
|---|---|---|---|---|
| Auth | login, forgot/reset, register (hard-coded role checkboxes, `auth/register.blade.php:143` TODO), MFA setup/verify (flagged off, broken — §8), SSO discover/redirect/callback/ACS/metadata | | | |
| Platform | `/` role-shaped landing; `/my` (`MyResponsibilitiesService`); `/hq/{node}` Business HQ (widget dashboards per node, 6 seeded system dashboards); dashboards builder (GridStack); notifications; global search + suggest; period selector (`ResolvePeriod`) | | | |
| Scoping | index/dashboard/create/edit/show/tree | store/update | `EntitiesGrid` | |
| Risk register | index/create/edit/show, map-control | store/update, map-control | `RisksGrid` | CSV |
| RCSA | dashboard, worksheet, matrix, controls | worksheet submit → `RcsaWorksheetSubmitted` | | |
| Assessments | index/select-risk/create (9-step chain)/show/edit | submit/approve/reject/resubmit via `ModuleApprovals` → `WorkflowEngine` | `RiskAssessmentsGrid` | |
| Controls & tests | index/create/edit/show; tests index/testing-dashboard/create/execute/evidence/review | review (`Gate review-control-test`) | `ControlsGrid`, `ControlTestsGrid` | |
| Treatments | dashboard/index/create/edit/show/review | approve | `TreatmentPlansGrid` | |
| Appetite | index | store/update/approve | | |
| Approvals | dashboard, history | | `ApprovalsHistoryGrid` | |
| KRI | dashboard/index/create/edit/show, record measurement, thresholds, breaches, acknowledge | | `KrisGrid`, `KriBreachesGrid` | |
| Periods / thresholds | calendar & close/reopen; re-baselining queue | approve | | |
| Loss events | dashboard/index/create/edit/show, status, RCA create/approve, attachments, near-misses (create/convert), approvals, reports | submitApproval | `LossEventsGrid`, `NearMissesGrid` | 6 CBN/Basel/NFIU CSVs |
| Issues | dashboard/index/create/edit/show, progress, remediation, attachments, ageing, closure, escalate | | `IssuesGrid` | |
| Campaigns / questionnaires | dashboard/index/create/show/respond/submission; questionnaires CRUD/publish; question library | | `CampaignsGrid`, `QuestionnairesGrid`, `QuestionLibraryGrid` | |
| Workflows | my-tasks index/show/act; dashboard; definitions; designer (Livewire); instance; publish | | | |
| Analysis | heatmap, bow-tie, trends, shared controls | | | |
| Quantification | dashboard, scenarios CRUD, simulate (queued `RunSimulationJob`), results, ICAAP, library, settings, 4 reports | | | capital adequacy, stress, contribution, regulatory pack |
| Regulatory | dashboard, calendar, deadlines, circulars, taxonomy | | `RegulatoryCircularsGrid` | |
| Imports / documents | imports index/create/mapping/process; document repository | `ProcessDataImportJob` | `DataImportsGrid` | |
| Reports | executive, board (+ board-pack sections), regulatory, custom, library, queue/status/download | `GenerateReportJob` | `ReportsLibraryGrid` | PDF (dompdf, 15 templates), XLSX |
| Emerging risk | index/create/edit/show | | `EmergingRisksGrid` | |
| AI (flag) | forecast, radar, regulatory pulse; 4 draft-text tools | | | |
| Admin | users (+roles), org settings, SSO settings, builder ×5 (Livewire), config bundles export/diff/import/rollback, webhooks + deliveries, API tokens, connectors + runs, jobs | | `AdminUsersGrid` | |

### 5.3 Roles and permissions (`database/seeders/RolesAndPermissionsSeeder.php`, 474 lines)

Roles (9, slugs): `super-admin, chief-risk-officer, risk-manager, risk-owner, risk-analyst, compliance-officer, board-member, loss-event-manager, issue-manager`. Permissions: **118**, named `resource.verb` (`risk.view`, `assessment.approve`, `kri.acknowledge_breach`, `quantification.run_simulation`, `admin.users`, `webhook.manage` …). Later migrations grant new permissions (`2026_08_14_120005`, `2026_08_15_120002`, `2026_08_16_120002`). Spatie usage: `@can/@canany` ×63 in views, `->can()` ×57, `hasRole` ×12. **No `app/Policies`.** Node-scoped authorization: `app/Support/Authorization/GraphScope.php` (`apply/applyThrough/isSubtreeLimited/rootPathFor` on `entities.hierarchy_path`), `ScopedToGraph::visibleTo`, `Controllers/Concerns/EnforcesNodeScope`; `config/authorization.php` names the full-org roles.

### 5.4 Cross-cutting subsystems (all backend, all stay)

- **Workflow engine** `app/Services/Workflow/` (12 + 12 subject bindings; `WorkflowEngine` 1,302 lines; `WorkflowLibrary` ships 10 processes; expression-language conditions; SLA sweep hourly; docs `docs/schema/workflow-engine.md`).
- **Scoring** `RiskScoringService` (579), `AssessmentChainService` (421, `deriveResidual()` axis-split), `ControlEffectivenessService`, `RiskMeasureRecorder`, `ScoringProfile` data model + `ScoringProfileTemplates`.
- **Measures/KRI** `MeasureService` (660), `KriMeasureBridge` (336, dual-writes legacy `kri_measurements` L256), `ThresholdRebaselineService`, `PeriodService`.
- **Quantification** `MonteCarloService` (seeded `Random\Randomizer`), `CurrencyService`, `RegulatoryReportService`.
- **Widgets** `WidgetDataService` (21 resolvers), `WidgetQueryEngine` + `WidgetSourceRegistry` whitelist, `DashboardResolver`, `WidgetContextResolver`, `WidgetPeriodResolver`.
- **Grids** `app/Grids/{GridDefinition, Column, Filter, RowAction, BulkAction, GridRegistry, GridExport}` + 22 definitions.
- **Integrations** REST API (`ApiResourceRegistry`, `QueryShaper`), webhooks (`WebhookDispatcher`, `OutboundUrlGuard`), connectors (`ConnectorRunner`, CSV/REST), MCP (`McpToolRegistry`, 6 tools), SCIM, SSO, API tokens with scopes + idempotency.
- **Config bundles** `Services/Configuration/*` (export/diff/import/rollback).
- **Reporting** `ReportDataService`, `BoardPackAssembler`, `DocumentRenderer` (dompdf/Excel), 15 PDF Blade templates, `ExportController` 16 CSV streams.
- **Jobs/schedule** 7 Horizon jobs (`TracksJobProgress` → `job_runs`), 25 commands / 16 schedule entries.
- **Events** 10 events / 8 listeners (`RiskAppetiteBreached` has no listener).
- **Notifications** `NotificationService` (raw insert to `notifications_log` + `FanOutNotificationsJob`); no `app/Notifications`.

### 5.5 Tests and CI

96 test files / ~950 methods (`tests/Feature/` 95 across 17 folders + 43 root; `tests/Unit/ExampleTest.php`). Guard tests worth protecting through the migration: `NoFabricatedNumbersTest` (provenance, not just RNG), `RouteAuthorizationTest`, `PreflightRouteGuardTest`, `AdminNavigationTest`, `TenancyIsolationTest`, `NodeScopeEnforcementTest`, `GraphScopeTest`, `SecurityConfigurationTest`, `MfaEnforcementTest`, `MfaFeatureGateTest`, `AuthenticationRateLimitTest`, `AuditTrailIntegrityTest`, `EvidenceStoragePrivacyTest`, `AssetResidencyTest`, `ScimProvisioningTest`, `SsoProvisioningTest`, `MachineTokenLifetimeTest`, `ListenerRegistrationTest`, `DemoLoopTest`, `UpgradeEndToEndTest`, and the four `Characterisation/` tests. CI (`.github/workflows/ci.yml`): PHP 8.2, `scripts/check-no-rng.sh`, `pint --test`, PHPUnit on SQLite — **no `npm run build`, no static analysis**. Deploy (`.github/workflows/deploy.yml`) runs on push to `main` **without depending on CI**.

---

## 6. Technical debt and security gaps (ranked by relevance to the migration)

1. **No Form Requests, no Policies, no API Resources** (`app/Http/Requests`, `app/Policies`, `app/Http/Resources` absent). 112 inline `validate()`; authorization = route middleware + six inline Gates + ad-hoc `organization_id !==` checks (`RiskAssessmentController::authorizeTenant` L668, `ReportController::assertSameTenant` L969). This is the biggest *conventions* gap vs ThirdLine's standard and is addressed module-by-module during the port.
2. **Fat controllers holding domain logic**: `QuantificationController` (ICAAP maths L612-780, `lognormalParametersFromMoments` L1386, reference-data `libraryScenarios()` L806-824, four report assemblers L905-1268); `LossEventController::store()` L224-311 (reference-code allocation, hard-coded ₦10 m regulatory threshold L266-269, canonical/legacy column mapping, threshold evaluated twice — again by the `LossEventCreated` listener); `AnalysisController::buildRiskMovementData()` L575-600 (whole register loaded 4× with hard-coded bands ≥20/≥12/≥5 despite `ScoringProfile` being data); `ReportController::board()` L169-215.
3. **Cross-tenant FK acceptance in validation**: 80 string `exists:<table>,id` rules vs 9 tenant-scoped `Rule::exists()->where('organization_id', …)`. The global scope does not apply to validator queries, so a submitted `risk_id`/`business_unit_id`/`reported_by` from another tenant passes (`LossEventController::store` L233-235). Form Requests are the natural place to fix this.
4. **Client-side scoring mirror** (§2.5) — duplicated logic with no parity gate.
5. **Dual-path data**: typed pivots (`risk_control_mapping`, 17 references) read alongside `object_relationships`; `kri_measurements` alongside `measure_values`; canonical vs legacy columns (`docs/schema/canonical-columns.md`, `deprecations.md`); legacy `approval_requests` + `ApprovalService` alongside `WorkflowEngine`; two org models (`business_units`/`entities`) alongside `objects` (13 `exists:business_units,id` rules). Not a migration blocker; the port must not *add* readers of the legacy paths.
6. **20 raw `DB::table()->insert` calls** bypass observers (webhooks, workflow triggers, tenancy stamping): `DynamicForm:183`, `PersistsConfiguredAttributes:114`, `ObjectSyncService:459`, `NotificationService:23`, `KriMeasureBridge:256`, `IdempotentRequest:92`, …
7. **MFA is non-functional and flagged off** (`config/features.php mfa_totp`; `EnsureMfaVerified.php:17-49` lists the three defects; `SECURITY-NOTES.md` L224). The platform has no working second factor. Out of scope for the port but the auth pages get rebuilt, so fix-while-porting is cheap (Phase 1 prompt).
8. **Sidebar sections ungated** (§5.1). Fixed for free by porting the nav to the `allowedRoles/permission` data model.
9. **Hard-coded config**: ₦10 m threshold; rating bands; `AiToolsController` `max_tokens 1200, timeout 90` (L179, L283); `RegulatoryReportService` `rwa_multiplier 12.5` (L389); per-view chart palettes.
10. **`@php` blocks** — 191 in 105 views; the sidebar is one 343-line `@php` block. Disappears with Inertia.
11. **Per-request raw queries in a View composer** for the bell (`AppServiceProvider:93-110`). Becomes a lazy shared prop.
12. **Deploy without CI dependency; CI without asset build or static analysis.**
13. **Repository hygiene**: SQLite `risk` DB, `.env`, `plans/`, `erm-update/`, `files_/`, `_to_delete/` (~40 MB) checked in; two divergent deploy procedures (`build-deploy.sh` cPanel zip vs `scripts/deploy.sh` git/systemd); `alpinejs` npm dependency unused.
14. **Untyped `SendNotification::handle(object)`**, `RiskAppetiteBreached` has no listener.

---

## 7. What the risk product has that ThirdLine does not (assets to protect)

Tenancy kernel with throwing `TenantContext`; 100 % route-permission coverage with a regression test; `GraphScope` node-scoped authorization + tenant-safe route binding; object graph + metadata builders + config bundles; period-aware measure engine with effective-dated thresholds and re-baselining; workflow v2 engine with designer; scoring profiles; seeded Monte Carlo + ICAAP; widget engine (21 types) + configurable dashboards per org node; universal `DataGrid` with saved views/inline edit/XLSX; REST API + OpenAPI + scoped tokens + idempotency + webhooks with SSRF guard + connectors + MCP; SSO (SAML + OIDC) + SCIM; self-hosted fonts/assets for data residency; ~950 tests including provenance and security guards; `app:preflight`.

Every one of these is backend or data. **None of it is touched by replacing Blade/Livewire with Inertia/React**, provided the port is done as a presentation swap and not a rewrite.
