# Phase 4 — Monitoring modules: KRI, periods, loss events, issues, campaigns, emerging risks

> Claude Code implementation prompt. Branch `migration/phase-4-monitoring`, based on Phase 3. Apply the per-module recipe from `phase-3-core-risk.md` verbatim. Characterisation tests come first in every module here because these modules compute regulatory figures.

## Modules, in order

### 4.1 KRI — `Risk/KriController` (627), views `risk/kri/*` (7)
- Backend debt to settle while here: the controller still reads legacy `KriMeasurement` (L96, L101, L266) while `KriMeasureBridge`/`MeasureService` write `measure_values`. New pages read **only** through `KriMeasureBridge`/`MeasureService`; do not add readers of `kri_measurements`. Leave the bridge's legacy dual-write (`KriMeasureBridge:256`) in place — retiring it is a deprecation-process job (`docs/schema/deprecations.md`), not this phase's.
- `KeyRiskIndicatorPolicy` (kri.view/create/edit/delete/record_measurement/acknowledge_breach). Pages: `Dashboard` (widgets `kpi_tile`, `sparkline`, `measure_table`), `Index` (grid), `Create/Edit/Show`, `RecordMeasurement` (modal), `Thresholds` (effective-dated bands editor — table form), `Breaches` (grid `KriBreachesGrid`) with acknowledge action. `KriBreachDetected` → `EscalateRiskOnKriBreach` path (Wave 3) untouched.

### 4.2 Periods and threshold re-baselining — `Risk/PeriodController` (174), `Risk/ThresholdController` (83), views `risk/periods/index`, `risk/thresholds/rebaseline`
`PeriodPolicy` (period.view/close/reopen), `ThresholdPolicy` (threshold.view/manage/rebaseline_approve — absorbs any inline check). `Periods/Index.jsx` calendar with close/reopen `ConfirmDialog`; `Thresholds/Rebaseline.jsx` approval queue.

### 4.3 Loss events, near misses, RCA — `Risk/LossEventController` (969), views `risk/loss-events/*` (10; `show` 687, `create` 517)
- **Extract `app/Services/LossEventService.php`** from `store()`/`update()` (L224-311 and the canonical/legacy mapping `retainedAttributes()/canonicalAttributes()` L180-222). Move the hard-coded `10000000` regulatory threshold (L266-269) into `RegulatoryThresholdService`/`config/quantification.php` (or `organizations.settings`), and evaluate it **once** — today `store()` calls `RegulatoryThresholdService` directly *and* the `LossEventCreated` listener (`EvaluateRegulatoryThresholds`) evaluates again. Keep the listener; remove the inline call.
- Characterise first: `tests/Feature/Characterisation/LossEventNetLossTest` and `RegulatoryThresholdServiceTest` exist; add `LossEventStoreCharacterisationTest` capturing the full stored row (both column sets) for three fixtures before extraction.
- `LossEventPolicy` (loss_event.view/create/edit/delete/approve/cbn_notify — absorbs `approve-loss-event` Gate), `NearMissPolicy`. Pages: `Dashboard` (widgets), `Index`, `Create/Edit/Show` (Show tabs: details, controls, RCA, attachments, approvals, timeline), `NearMisses/{Index,Create}` + convert action, `Rca/{Create,Show}`, `Approvals`, `Reports` (links to the six CBN/Basel/NFIU CSV exports in `ExportController` — unchanged).

### 4.4 Issues — `Risk/IssueController` (817), views `risk/issues/*` (7)
- Extract `app/Services/IssueDashboardService.php` from `dashboard()` (L37-80, 12 COUNT queries + ageing buckets) and `IssueAgeingService` from `ageing()`; characterise both. `IssueEscalationService` already exists — use it.
- `IssuePolicy` (issue.view/create/edit/delete/close/escalate). Pages: `Dashboard`, `Index`, `Create/Edit/Show` (progress updates, remediation actions, attachments as sub-forms posting to existing routes), `Ageing` (widget `activity_table` + bands), `Closure`.

### 4.5 Campaigns and questionnaires — `Risk/CampaignController` (405), `Risk/QuestionnaireController` (193), views `risk/campaigns/*` (6), `risk/questionnaires/*` (5)
`AssessmentCampaignPolicy` (campaign.view/create/manage/respond/review), `QuestionnairePolicy` (questionnaire.view/create/edit/publish). `Campaigns/Respond.jsx` renders questionnaire sections dynamically (reuse `DynamicForm` field renderers for question types). Question library grid.

### 4.6 Emerging risks — `Risk/EmergingRiskController` (197), views `risk/emerging/*` (4)
`EmergingRiskPolicy`; grid + `Create/Edit/Show`.

## Acceptance criteria
1. `tests/Feature/Measures/*` (8), `tests/Feature/Issues/*`, `tests/Feature/Notifications/*`, `Characterisation/*` green; new characterisation tests byte-identical before/after extraction.
2. `grep -n "10000000" app` returns nothing; a test proves the threshold is evaluated exactly once per loss-event create (spy on `RegulatoryThresholdService`).
3. `grep -rn "KriMeasurement::" app/Http/Controllers` returns nothing.
4. Policies exist and are registered for `KeyRiskIndicator, Period, MeasureThreshold, LossEvent, NearMiss, Issue, AssessmentCampaign, Questionnaire, EmergingRisk`; each has allow/deny + cross-tenant tests.
5. Scheduled commands `kri:check-breaches`, `issues:check-overdue`, `treatments:check-overdue`, `measures:rebaseline-thresholds` run green in `tests/Feature/Console/*`.
6. `resources/views/risk/{kri,periods,thresholds,loss-events,issues,campaigns,questionnaires,emerging}` deleted; `RouteAuthorizationTest`, `NavigationPermissionGateTest`, `NoFabricatedNumbersTest` green.
