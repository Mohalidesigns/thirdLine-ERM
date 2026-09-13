# Phase 3 — Core risk modules

> Claude Code implementation prompt. Branch `migration/phase-3-core-risk`, based on Phases 1 and 2. Work one module at a time in the order below; each module is its own commit that also deletes the module's Blade views.

## The per-module recipe (apply to every module in this and later phases)

For module `X` with controller `app/Http/Controllers/Risk/XController.php`:

1. **Characterise** any method that computes a figure (score, rating, total) with a test in `tests/Feature/Characterisation/` *before* touching it.
2. **Form Requests** `app/Http/Requests/X/{StoreXRequest,UpdateXRequest,…}.php`: move every inline `$request->validate()` in; `authorize()` calls the policy; every foreign key uses `Rule::exists('table','id')->where('organization_id', TenantContext::organizationId())` (never a string `exists:` rule — see `02 §6.3`); configured attributes via `ValidatesConfiguredAttributes` (Phase 2).
3. **Policy** `app/Policies/XPolicy.php` in ThirdLine's shape (`app/Policies/InvestigationCasePolicy.php`): permission check first (`$user->can('x.view')`), then ownership/node scope (`ScopedToGraph`/`GraphScope`), then lifecycle rules. Register in `AppServiceProvider` (`Gate::policy`). Fold any of the six inline `Gate::define` closures (`AppServiceProvider:116-193`) that belong to this module into the policy and delete the closure.
4. **Service extraction** for any controller method over ~40 lines or that touches more than one model (ThirdLine §4.2 rule).
5. **Controller**: constructor-inject services; each action = `Gate::authorize` (or Form Request) → service → `Inertia::render('X/Page', props)` or `redirect()->route(...)->with('success', …)`. Eager-load; `paginate(15)->withQueryString()` where not using the grid; echo `filters`.
6. **Pages** `resources/js/Pages/X/{Index,Create,Edit,Show}.jsx` composed from ThirdLine primitives + Phase 2 `DataGrid`/`DynamicForm`/`Widget`; `useForm`, `route()`, `<Head>`, `PageHeader` with breadcrumbs, `InputError` per field, `RichTextEditor` on narrative fields (Decision: which fields — default `description`, `rationale`, `notes`).
7. **Tests**: `assertInertia` per page; policy allow/deny per role and a cross-tenant 404/403; Form Request cross-tenant `exists` rejection; existing module tests green.
8. **Delete** `resources/views/risk/x/**` and any inline `<script>` they carried. Never leave a Blade fallback.

## Modules, in order

### 3.1 Scoping / entities — `Risk/ScopingController` (425), views `risk/scoping/*` (6)
Index already flipped in Phase 2 (`EntitiesGrid`). Add `Dashboard`, `Create`, `Edit`, `Show` (+ tree from `hq/partials/tree` as `EntityTree.jsx`). Policy `EntityPolicy` (entity.view/create/edit/delete + node scope via `GraphScope::rootPathFor`).

### 3.2 Risk register — `Risk/RiskRegisterController` (552), views `risk/register/*` (4; `show` 584 lines, `create` 474)
`RiskPolicy`; `StoreRiskRequest`/`UpdateRiskRequest`; `MapControlRequest`. `Show.jsx` tabs: Overview (with `DynamicDetail`), Assessments, Controls, KRIs, Treatments, Audit trail (`RiskAuditTrail`), Attributes (`DynamicForm`, replacing the Livewire editor — note Wave 2's "attribute appears twice" comment: render read-only on Overview only if the Attributes tab is absent). The four `fetch()` AI draft helpers (`register/create.blade.php:433`) become `AiDraftButton.jsx` calling the existing `risk.ai.tools.*` routes with axios, shown only when `features.ai_intelligence`.

### 3.3 Risk assessments — `Risk/RiskAssessmentController` (801), views `risk/assessments/*` (4; `create` 876)
- **Decision 5b applies.** Preferred: `POST risk/assessments/preview` (`permission:assessment.create`) → `AssessmentChainService::preview(array $input): array` returning `{ impacts, inherent{score,rating}, controls[{id,design,operating,effective,finding}], aggregate, residual{likelihood,impact,score,rating}, target }`. `Create.jsx` calls it (axios, 300 ms debounce) as the user edits; the Alpine `assessmentChain()` (`create.blade.php:675-875`) is deleted. Tenant-configured residual formulas (`ScoringProfile`) are therefore honoured in the preview — a behaviour the old screen lacked (`create.blade.php:813-815`).
- If the JS mirror is kept instead: `resources/js/lib/assessmentChain.js` + `tests/js/mirror-parity.mjs` modelled on ThirdLine's, feeding identical fixtures to `AssessmentChainService` and the JS, wired into CI as a required job.
- `AssessmentPolicy` absorbs the `approve-risk-assessment`/`resubmit-risk-assessment` Gates; submit/approve/reject/resubmit continue through `ModuleApprovals` → `WorkflowEngine` (no change).
- Pages: `Index` (grid), `SelectRisk`, `Create` (9-step chain as a stepper component), `Show`, `Edit`.

### 3.4 Controls and control tests — `Risk/ControlController` (318), `Risk/ControlTestController` (346), views `risk/controls/*` (9)
`ControlPolicy`, `ControlTestPolicy` (absorbs `review-control-test`, `resubmit-control-test` Gates). Evidence upload via `useForm({ forceFormData: true })` → `FileUploadService` (unchanged). Pages: controls `Index/Create/Edit/Show`; tests `Index/TestingDashboard/Create/Execute/Show` with `ReviewDialog` (ThirdLine `ConfirmDialog` variant).

### 3.5 Treatment plans — `Risk/TreatmentPlanController` (603), views `risk/treatments/*` (6)
`TreatmentPlanPolicy` (absorbs `approve-treatment-plan`, `resubmit-treatment-plan`). Dashboard charts become `Widget`s (`WidgetDataService` already has `donut`, `stacked_bar_bands`, `timeline`; add a `TreatmentProgressResolver` only if no existing resolver fits — do not hand-build Chart.js in the page). `TreatmentCompleted` event path (Wave 3) untouched.

### 3.6 Risk appetite — `Risk/RiskAppetiteController` (184), view `risk/appetite/index`
`RiskAppetitePolicy`; `Index.jsx` with inline create/edit modal (ThirdLine `Modal`).

### 3.7 Approvals, my-tasks, workflow instances — `Risk/ApprovalController` (105), `Risk/MyTaskController` (176), `Risk/WorkflowController` (302, instance/dashboard/definitions only — the **designer** is Phase 6), views `risk/approvals/*`, `risk/my-tasks/*`, `risk/workflows/{index,show,dashboard}`
`WorkflowTaskPolicy` (task.view/act + assignee/candidate-role checks from `TaskQueryService`). `MyTasks/Show.jsx` renders the task form (outcome buttons + comment) and posts to the existing `act` route.

### 3.8 RCSA — `Risk/RcsaController` (534), views `risk/rcsa/*` (4)
`RcsaPolicy` (rcsa.view/submit). Worksheet is a large editable table — build `RcsaWorksheet.jsx` on `GridTable` from Phase 2 (inline edit mode), submit → existing `RcsaWorksheetSubmitted` event. Matrix = `Widget` type `heatmap`.

## Acceptance criteria
1. `DemoLoopTest`, `UpgradeEndToEndTest`, `tests/Feature/{Assessments,Treatments,Scoring,Workflow}/*`, `NodeScopeEnforcementTest`, `GraphScopeTest`, `TenancyIsolationTest`, `RouteAuthorizationTest`, `NavigationPermissionGateTest` green.
2. `grep -rn "Gate::define" app/Providers` returns **none** of the six risk-module closures (only `Gate::before` remains).
3. `grep -rn "'exists:" app/Http/Requests` returns nothing; every Form Request has a test proving a foreign id from another organisation is rejected.
4. `tests/Feature/Assessments/AssessmentPreviewTest.php`: preview equals `AssessmentChainService` output for three seeded scenarios including a tenant with a custom residual formula; `NoFabricatedNumbersTest` traces every number on `Assessments/Create` to the preview response.
5. `resources/views/risk/{scoping,register,assessments,controls,treatments,appetite,approvals,my-tasks,rcsa}` no longer exist; `resources/views/risk/workflows/designer.blade.php` still does (Phase 6).
6. PHPStan baseline shrinks (no new entries).
