# Phase 5 — Analytics, quantification, regulatory, reports, imports, AI screens

> Claude Code implementation prompt. Branch `migration/phase-5-analytics-reporting`, based on Phase 3 (Phase 4 may run in parallel). This phase carries the largest service extraction in the programme (`QuantificationController`, 1,611 lines) and touches every figure `NoFabricatedNumbersTest` guards. Characterise before extracting; extract before porting pages.

## Modules, in order

### 5.1 Analysis — `Risk/AnalysisController` (851), views `risk/analysis/*` (4)
- Extract `app/Services/Analysis/RiskMovementService.php` from `buildRiskMovementData()` (L575-600). Replace the hard-coded bands `>= 20 / >= 12 / >= 5` with the tenant's `ScoringProfile` bands via `RiskScoringService`; replace the four whole-register `->get()` loads with `RiskRepository` point-in-time reads (`app/Repositories/RiskRepository.php` exists for exactly this). Characterise with a fixed register first; the movement counts must match for the default 5×5 profile.
- Heat map and bow-tie: widget types `heatmap` and (add) `bowtie` resolver in `app/Services/Widgets/Types/`; shared controls ("correlation" — route name stays `risk.analysis.correlation`) as `network` widget. Pages `Analysis/{Heatmap,Bowtie,Trends,SharedControls}.jsx` are thin wrappers around `Widget`.

### 5.2 Quantification and ICAAP — `Risk/QuantificationController` (1,611), views `risk/quantification/*` (15)
- **Extract first, pages second.** New services under `app/Services/Quantification/`:
  - `ScenarioService` (scenario CRUD rules, `lognormalParametersFromMoments()` L1386 → `Distributions::lognormalFromMoments()` in `app/Support/Quantification/Distributions.php`),
  - `ScenarioLibrary` (the PHP-literal library at L806-824 becomes a versioned JSON/PHP config `config/quantification_library.php` with a `source` field per entry — the "CBN ORMS Data" provenance must be explicit; `NoFabricatedNumbersTest` will read it),
  - `IcaapService` (`icaap()` L612-780: CBN minimum CAR/buffer resolution from `config/quantification.php`, CAR recomputation from kobo inputs, variance reconciliation, stress rows `stressImpactRows()` L1522),
  - `QuantificationReportService` (the four report assemblers L905-1268),
  - `SimulationService` (run/cancel orchestration over `MonteCarloService` + `RunSimulationJob`; the `alert_amber => 12` literal at L842 → config).
  - Characterisation: existing `MonteCarloService` test; add `IcaapCharacterisationTest` and `QuantificationReportsCharacterisationTest` capturing full JSON for seeded fixtures **before** any extraction, and keep them as permanent tests.
- `QuantificationScenarioPolicy`, `SimulationRunPolicy`, `IcaapAssessmentPolicy` (quantification.view/create/run_simulation/approve_icaap). Pages: `Dashboard`, `Scenarios/{Index,Create,Edit,Show}`, `Simulate` (form → job → `useJobProgress` → results), `Results/{Index,Show}` (widgets `lec_curve`, `tornado`, `gauge`, `cumulative_line` already exist), `Icaap/{Index,Show}` with sign-off, `Library` (import from `ScenarioLibrary`), `Settings`, `Reports/{CapitalAdequacy,StressTesting,RiskContribution,RegulatoryPack}`.

### 5.3 Regulatory — `Risk/RegulatoryComplianceController` (239), views `risk/regulatory/*` (9)
`RegulatoryPolicy` (regulatory.view/manage/file). Pages `Dashboard`, `Calendar`, `Deadlines/{Index,Create}`, `Circulars/{Index,Create,Show}` (grid), `Taxonomy`. `regulatory:check-deadlines` untouched.

### 5.4 Reports — `Risk/ReportController` (1,078), views `risk/reports/*` (7) — **`resources/views/reports/pdf/**` stays**
- Extract `app/Services/Reporting/BoardReportService.php` from `board()` (L169-215, `applyTreatmentStatus` L1015, `getControlEffectivenessRate` L1038, `getTreatmentCompletionRate` L1062) and `ExecutiveReportService` from `executive()`; `ReportDataService`/`BoardPackAssembler`/`DocumentRenderer` unchanged. Characterise both.
- `GeneratedReportPolicy` (report.view/generate/export) absorbing `assertSameTenant()` (L969).
- Pages `Reports/{Executive,Board,Regulatory,Custom,Library,Status}.jsx`; status polling (`reports/status.blade.php:91` `fetch`) → `useJobProgress`; download links to existing routes; board-pack section picker as a `useForm` checklist.
- Rich text: report narrative fields use `RichTextEditor`; the PDF templates render them through `EditorJsHtmlRenderer` (imported from ThirdLine in Phase 0 — verify it is wired into `DocumentRenderer`).

### 5.5 Exports, imports, documents — `Risk/ExportController` (766, unchanged), `Risk/DataImportController` (151), `Risk/DocumentRepositoryController` (192), views `risk/imports/*`, `risk/documents/*`
`DataImportPolicy` (import.view/create/process); `Imports/{Index,Create,Mapping}` (mapping table as `GridTable` inline edit), process → `ProcessDataImportJob` + `useJobProgress`. `Documents/Index.jsx` read-only repository over `FileUploadService` listing. Exports: an `ExportMenu.jsx` component listing the 16 CSV routes filtered by `auth.permissions` (`report.export`).

### 5.6 AI screens — `Risk/AiIntelligenceController` (179), `Risk/AiToolsController` (672), views `risk/ai/*` (3)
- Move `max_tokens 1200, timeout 90` (L179, L283) into `config/services.php llm`.
- Pages `Ai/{Forecast,Radar,RegulatoryPulse}.jsx` render `RiskForecastService`/`RegulatoryPulseService` outputs through widgets (deterministic — `docs/ai-number-provenance.md` mapping must still hold; update it for any renamed prop). `AiDraftButton.jsx` (Phase 3) is the only UI for the tools. Gate every page on `features.ai_intelligence` **and** `permission:ai.view` (route) — the nav item already carries both.

## Acceptance criteria
1. `NoFabricatedNumbersTest` green with **no allowlist additions**; `scripts/check-no-rng.sh` green.
2. `Characterisation/*` (existing four + `IcaapCharacterisationTest`, `QuantificationReportsCharacterisationTest`, `RiskMovementCharacterisationTest`, `BoardReportCharacterisationTest`) green and byte-identical before/after extraction.
3. `app/Http/Controllers/Risk/QuantificationController.php` under 300 lines; no method over 40 lines; `grep -n "12.5\|10.0\|15.0\|=> 12" app/Http/Controllers` returns nothing (constants live in config).
4. `config/quantification_library.php` entries each carry `source`; a test asserts it.
5. Report generation end-to-end from React: create → job → progress → download (`tests/Feature/Reports/ReportGenerationFlowTest.php`); PDF templates unchanged (`git diff --stat resources/views/reports/pdf` empty).
6. `php artisan scramble:export` output unchanged (API untouched) — diff against the committed spec.
7. `resources/views/risk/{analysis,quantification,regulatory,reports,imports,documents,ai}` deleted; `risk/dashboard.blade.php` (Command Centre) ported to `Pages/Dashboard.jsx` composed of `Widget`s from the seeded `erm-hq` dashboard — the seven inline Chart.js constructions (L538-891) are deleted, not ported.
