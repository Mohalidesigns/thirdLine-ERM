# ERM Remodel Master Plan
## Becoming the definitive Enterprise Risk Management platform for Nigeria and Africa

**Prepared:** 8 August 2026
**Project root:** `/Users/mac/Documents/devs/laravel/grcsuite/risk copy`
**Benchmark target:** Corporater Enterprise Risk Management (corporater.com) + global ERM/GRC leaders
**Strategy decision:** Evolve the Laravel codebase in place. No rewrite.

---

# 0. Executive summary — the bet

## 0.1 What the analysis actually found

We benchmarked the current solution against Corporater's ERM product (site, 4-page solution brief, 12 product screenshots) and against the global field — Riskonnect, MetricStream, Archer, IBM OpenPages, ServiceNow IRM, LogicManager, SAI360, Diligent, Resolver, Protecht, Camms, Origami — plus the standards those products implement and the Nigerian/African regulatory and operating environment.

Three findings drive everything in this plan.

**Finding 1 — Corporater's moat is architecture, not features.**
Corporater does not sell an ERM application. It sells the *Corporater Business Management Platform*, a metadata-driven object platform with 250+ configurable business objects, and ships ~30 "solutions" that are configuration packages over the same engine. Four architectural choices do the work:

| Choice | What it buys |
|---|---|
| **Digital Twin of the Organization** — a typed graph of enterprise nodes (entity, division, BU, process, system, asset, product, geography, vendor) with governance artefacts attached to nodes | Context is inherited, not re-entered. Roll-up, permissioning and aggregation fall out of the graph. Reorganise the company and everything moves with it. |
| **Period-aware measure model** — `(measure × node × period × scenario) → value`, inherited from their Balanced Scorecard origins | Risk scores become time series. "Risk profile as at 31 December" is a query, not a reconstruction. KRIs and KPIs are one object type. Assessment cycles are natural. |
| **Context-bound widgets** — a widget is a bound view over an object query that inherits scope from the page it sits on | One dashboard template serves the whole hierarchy. Per-role, per-node dashboards without a reporting project. |
| **Configure, don't code** — new object types, fields, relationships and workflows are metadata writes | The Nth solution is cheap and the N+1th cross-domain integration is free. |

Copying their **feature list** produces a checklist product. Copying their **architecture** produces a platform that out-runs them, because every subsequent module costs a fraction of what it costs us today.

**Finding 2 — we already own things Corporater does not, and they are the right things.**
The current codebase has genuine, schema-level Nigerian regulatory depth that no international vendor ships: CBN / NFIU (STR & CTR) / BOFIA / NDIC / EFCC / police reportability flags with real deadlines and citations (`RegulatoryThresholdService`), Basel L1/L2/L3 **plus** CBN ORMS dual taxonomy on loss events, five-way recovery decomposition, a working Poisson–Lognormal Monte Carlo engine with VaR at four confidence levels, and an ICAAP model with the correct CBN capital structure (CET1/T1/T2, RWA, CAR, 10% minimum + 2.5% conservation buffer, Pillar 2A by risk type, Pillar 2B stress buffer, board approval and CBN submission tracking). Most mid-market GRC tools have *nothing* in the quantification layer. Corporater's own quantification is unevidenced beyond "Monte Carlo".

**Finding 3 — the Nigerian/African environment is the durable moat, and there is an open window.**
- **Data residency is now a gating requirement.** CBN Circular PSS/DIR/PUB/CIR/001/004 (15 June 2026) requires all Nigerian payment transaction data to be stored and managed in Nigeria by **1 January 2027**, reinforced by NITDA's National Cloud Policy 2025 for financial, health and government data. Most global SaaS competitors will not have a Nigeria-resident tier in time.
- **Three dated regulatory waves have created simultaneous demand.** Banks completed recapitalisation on 31 March 2026 and now face SREP capital add-ons tied to risk-management quality; ~43 insurers recapitalised on 31 July 2026 with NAICOM's risk-based capital framework next and **no incumbent software**; the FRC's IFRS S1/S2 roadmap makes sustainability reporting mandatory for PIEs from **1 January 2028** and names an ERM framework as a Stage 3 submission artefact.
- **No established Nigerian-built ERM platform exists.** The top of the market runs global tools bought under regulatory pressure and typically underused. The middle and bottom — mid-tier banks, recapitalised insurers, MFBs, PFAs, PSPs, listed corporates, MDAs — run on Excel. The most credible challenger positioning in the space is currently being demonstrated by **Pirani**, a Colombian vendor at **$276/month flat**, content-marketing directly at CBN/BoG/PA-regulated institutions. That is the position a Nigerian vendor should own outright.
- Global vendors will eventually copy a regulatory content library. **They will not rebuild their architecture for 2G connectivity and daily power cuts, and they will not price in naira.**

## 0.2 The bet, in one paragraph

> Rebuild the foundation as a **metadata-driven object graph with a period-aware measure model** — Corporater's architecture, which we can reach from where we already are because `entities`/`entity_types` is an embryonic version of it. Then out-run Corporater on the two axes they cannot defend: **quantification depth** (climb from level 4 to levels 5–6 — FAIR, loss exceedance curves, copula-correlated portfolio aggregation, control ROI, backtesting) and **environment fit** (offline-first PWA, USSD/WhatsApp front-line capture, inflation-indexed thresholds, naira-native multi-currency, Nigeria-resident deployment, pre-loaded CBN/SEC/NAICOM/PenCom/NDPC/FRC content). Sell to the mid-market that global vendors cannot economically reach, at naira flat-rate pricing that per-seat licensing structurally cannot match.

## 0.3 Positioning statement

> **The ERM platform built for how African organisations actually operate.**
> International standards conformance (ISO 31000, COSO ERM 2017, ISO 27005, Basel, IFRS S1/S2) with Nigerian regulatory content pre-loaded, quantification that produces naira answers a board can act on, and an interface that works on a branch officer's phone at 2G with the generator off.

## 0.4 What we are asking for

| | |
|---|---|
| **Duration** | 44 weeks to full target state; **13 weeks to a demo that wins deals** |
| **Team** | 4–6 engineers (2 backend/platform, 1 frontend, 1 full-stack, 1 data/quant, 0.5 DevOps), 1 risk domain SME, 1 designer part-time |
| **Sequence** | Phase 0 stabilise → Phase 1 platform core → Phase 2 Corporater parity → Phase 3 Africa moat → Phase 4 beyond parity → Phase 5 grounded AI |
| **Non-negotiables** | Tenant isolation, RBAC enforcement, schema debt cleanup and the removal of fabricated AI happen in Phase 0 and block everything else |

---

# 1. Where we are today — the honest as-is

Full detail is in the codebase audit; this is the load-bearing summary.

## 1.1 Stack

Laravel 12 / PHP 8.2, three production composer dependencies (`framework`, `tinker`, `spatie/laravel-permission`). 60 migrations, ~50 domain tables, 30 controllers, 142 Blade views, 203 named routes. **No `routes/api.php`.** Front end is Blade with Tailwind, Alpine and Chart.js loaded **from public CDNs at runtime** — the Vite pipeline exists in `package.json`/`vite.config.js` and `@vite` appears in zero Blade files. Auth is hand-rolled; MFA middleware exists but is registered on no route; a dormant dev auto-login middleware ships in the repo.

## 1.2 What is genuinely strong — protect these

1. **Nigerian regulatory depth in the schema, not the marketing.** `loss_events` carries CBN / NFIU-STR-CTR / BOFIA / NDIC / EFCC / police reportability with deadlines and reference numbers; `issues` carries CBN-examination-finding and NDPA-breach fields; `RegulatoryThresholdService` encodes real citations (CBN BSD/DIR/GEN/LAB/07/014, AML/CFT Act 2022 §6(1), NDIC Act 2023 §41, CBN Cyber Security Framework 2021) with the correct clocks (7 days CBN, 24 hours NFIU).
2. **Loss Event Management is enterprise-grade** — Basel L1/L2/L3 **and** CBN ORMS taxonomies, five-way recovery decomposition, insurance-claim lifecycle, GL account and cost centre, indirect cost and disruption hours, staged approval log with `days_in_stage`, structured 5-Whys RCA with its own approval chain, control-failure linkage with failure type, near-miss capture with a promotion path.
3. **Working quantification** — Poisson–Lognormal compound loss, 10,000 iterations, VaR at 90/95/99/99.9, percentile curve, per-scenario and aggregate results, contributions — coupled to a correctly structured ICAAP model.
4. **Monetary precision** — amounts in **kobo as `bigInteger`**, not floats.
5. **An event-driven backbone that actually fires** — 9 domain events, 7 listeners, a `domain_events` outbox with retry counters. The Controls → Risk → Treatment → KRI feedback loop is wired, not diagrammed.
6. **Config-driven issue escalation** — `issue_escalation_rules` (priority × source × level × role × days-overdue), executed on a schedule, logged with acknowledgement, with a CBN-examination-finding accelerator.
7. **Complete control testing** — 4 test types, 4 results, full scheduled → in_progress → pending_review → completed/rejected lifecycle with tester/reviewer separation enforced by Gates and evidence upload.
8. **Weighted control-effectiveness aggregation** via `risk_control_mapping.control_weight` + `is_key_control` — better than the binary linkage most tools ship.
9. **Risk hierarchy with roll-up** — `parent_risk_id`, `risk_level`, `hierarchy_path`, `roll_up_weight`, and `Risk::calculateRollUpScore()`.
10. **An embryonic digital twin** — `entity_types` (L0–L4) + `entities` (self-tree, owner + delegate, `regulatory_frameworks` json, `risk_appetite_level`, `category_appetites`, `metadata`), with `entity_id` on risks, controls, issues, loss events and KRIs. **This is the seed of the target architecture.**
11. **On-prem LLM with disciplined engineering** — `LlmService` never throws, has a real health check, JSON mode with fallback, 24-hour caching. The data-residency story is credible.
12. **A questionnaire schema ahead of the market** — 8 question types including matrix and file upload, section and question weights, `scoring_rules` json, `conditional_logic` json, 4 scoring methods, cross-org global question library.

## 1.3 What must be fixed before anything else — Phase 0 blockers

**Security and tenancy (critical)**
- **No tenant isolation enforcement.** Zero global scopes. `grep addGlobalScope` → 0 hits. Isolation depends on every developer remembering `->where('organization_id', $orgId)` in 30 controllers.
- **The `?? 1` org fallback** appears **166 times across 22 files** — concentrated in `LossEventController` (22), `QuantificationController` (20) and `IssueController` (19). A user with `organization_id = null` transparently reads and writes organization 1.
- **A cross-tenant read in the quantification engine.** `MonteCarloService::runSimulation()` line 49 does `QuantificationScenario::whereIn('id', $scenarioIds)` with **no organization filter at all**.
- **`ReferenceCodeService::generate()` has no `organization_id` filter** (lines 17–20), so reference codes are already generated against a global cross-tenant pool.
- **RBAC defined but not enforced.** 46 permissions and 9 roles seeded; **0 routes use `permission:` middleware** (`CheckPermission` is aliased at `bootstrap/app.php:16` and never used); 9 `@can` directives across just 5 of 142 views; 7 Gates. Any authenticated user can reach any of the 203 routes.
- **MFA unenforced** — `EnsureMfaVerified` is not aliased and is on no route.
- **`AutoLoginDev` middleware ships in the repo** (auto-authenticates `User::first()` when `APP_ENV=local`). It is **registered nowhere** — not in `bootstrap/app.php`, not on any group — so it is dormant dead code to be deleted, not an active bypass. The live exposure is the absence of route authorization above, not this file. Separately, `.env` has `APP_ENV=local`, `APP_DEBUG=true`, and live SMTP credentials for `info@atherislimited.com`.
- **One upload endpoint has no mime restriction at all.** `LossEventController:555` accepts any file type up to 20 MB, while `ControlTestController:273` and `IssueController:737` both restrict to 13 types at 10 MB. Treat the unrestricted endpoint as a Phase 0 security item.
- **No SSO** — no SAML, OIDC, LDAP or Socialite.

**Integrity of the numbers**
- **The AI Intelligence module is fabricated.** `AiDataService` and `AiIntelligenceController` use `mt_rand()` at 12 identified call sites; escalation probabilities are hardcoded `[0.87, 0.73, …]`; model metrics are hardcoded (`accuracy 87.3, auc 0.912, model_version '2.4.1'`); peer benchmarks are `mt_rand(2,5)`. **Shipping this to a Nigerian bank is a reputational and regulatory event, not a feature.** It must be removed or rebuilt on real data before any customer sees it.
- **`RcsaController::storeWorksheet()` is a stub** (line ~266) — worksheet submissions are silently discarded after a success message.
- **`RiskAppetiteService::compareAgainstActual()` reads five columns that do not exist** (`tolerance_upper`, `capacity`, `appetite_type`, `tolerance_lower`, `notes`) and silently falls through to hardcoded defaults. The controller and service disagree.
- **`AuditTrailService` writes `class_basename($entity)` ("Risk") while `Risk::auditTrail()` queries `'risk'`** — so `$risk->auditTrail` returns nothing.
- **Two conflicting rating bands** — `RiskScoringService::calculateRating()` uses `>=5` for Medium; `Risk::inherentRating()` uses `>=6`.
- **Two conflicting control effectiveness maps** — `Control::EFFECTIVENESS_PERCENT_MAP` (100/50/0) vs `ControlEffectivenessService::$effectivenessMap` (95/80/60/37/12).
- **`RiskScoringService::calculateMaxImpact()` omits `impact_strategic`** which `RiskAssessment::impactScore()` includes.
- **`DataImportController::createRecordForType('issues')`** writes `issue_code`; the column is `issue_reference`.
- **`ReferenceCodeService::generate()`** has an unguarded read-then-write race and lexicographic ordering that breaks past 9999.

**Schema debt**
- Migration `2026_02_22_200038_align_schema_with_controllers.php` **added parallel field sets rather than renaming**, across nine tables (`treatment_plans`, `loss_events`, `issues`, `loss_event_rca`, `key_risk_indicators`, `risk_assessments`, `risk_control_mapping`, `risks`, `near_misses`). Every add is `Schema::hasColumn`-guarded, so pre-existing names were skipped and not every added column is a duplicate. The genuine semantic duplicate pairs: `treatment_plans` ~10 (`action_title`/`treatment_title`, `progress_pct`/`progress_percentage`, `cost_estimate_ngn`/`estimated_cost`), `loss_events` ~10 (`title`/`event_title`, `gross_loss_amount_kobo`/`gross_loss_amount`, `current_status`/`status`, `event_severity`/`severity`), `issues` ~6, `loss_event_rca` ~5. Models paper over these with coalescing accessors, so every query, export, report and import has two possible sources of truth per duplicated field.
- **Two org models** (`business_units` tree + `entities` tree), both populated, not unified.
- **Two taxonomies.** `risk_categories` is the one the register uses. `risk_taxonomies` has **no foreign key from any table** but is not dead — it has a live CRUD surface (`RegulatoryComplianceController:202/220/224`, `views/risk/regulatory/taxonomy.blade.php`). It is a disconnected parallel tree to be **merged, not deprecated**.
- **Three approval mechanisms** (`approval_requests`, per-module `approved_by`/`status`, `workflow_instances`).
- **Two near-miss representations** (`near_misses` table + `loss_events.is_near_miss`).
- **Loose polymorphism with no morph map** in `approval_requests`, `workflow_instances`, `risk_audit_trail`, `notifications_log`.
- **Reference codes globally unique, not org-scoped** — `control_tests.test_code`, `loss_events.event_reference`, `near_misses.reference`, `issues.issue_reference`, `quantification_scenarios.scenario_reference`, `simulation_runs.simulation_reference`, `assessment_campaigns.campaign_code`. Cross-tenant collision.

**Configuration that exists but is ignored**
`organizations.settings->risk_settings` and `->risk_thresholds` are written by `OrganizationSettingsController` and **read by nothing**. `workflow_definitions.escalation_rules` — stored, never read. `quantification_scenarios.stress_multiplier_*` and `simulation_runs.stress_config` — stored, never applied. `simulation_runs.correlation_method` **defaults** to `GAUSSIAN_COPULA` in the schema (`200031:22`) while `MonteCarloService` sums scenario losses independently — though `QuantificationController:350` writes `'independent'`, so only the schema default misrepresents the engine. Fix the default now; implement the copula in WP-19. `key_risk_indicators.automation_config`/`is_automated` — no automation code. `entities.risk_appetite_level`/`category_appetites` — unused by the appetite module. `risk_kri_mapping` — documented as unused in `Risk.php`.

**Hardcoded where it must be configurable**
5×5 matrix in three places (`RiskScoringService:103-112`, `AnalysisController:43-57`, `heatmap.blade.php:77`); rating bands 20/12/5; impact = max of dimensions; residual = inherent × (1 − ctrlEff); regulatory thresholds as PHP constants in `RegulatoryThresholdService:9-21` **duplicating** the `quantification_settings` columns an admin can actually edit (the CAR 10.0% / buffer 2.5% / MPR 27.5% values themselves *are* configurable DB defaults — it is the service that ignores them); inconsistent upload policy across three controllers with one unrestricted; workflow entity types; radar categories; import field maps; brand colours inline in the layout (no white-labelling). Session lifetime is 120 minutes (`config/session.php:35`) with no per-role idle timeout for privileged roles.

## 1.4 What is absent entirely

Multi-currency (`loss_events.currency` is the only currency field and is used by no calculation; `cost_estimate_ngn` and `financial_exposure_ngn` bake NGN into column names). Multi-language (no `lang/` directory; 28 `__()` calls across 142 views; `₦` hardcoded in 28 views). Entity versioning / point-in-time snapshots. **PDF output of any kind.** The board, regulatory and executive reports render as on-screen Blade views (`ReportController:120, 256, 362`) with **no document output at all**; the only file output in the system is CSV, from `ReportController::generateCustom()` (`:519`) and the 16 `ExportController` methods. `generateCustom` validates `format in:pdf,excel,html,pptx` and then collapses every one of them to CSV (`:496`). Excel (import validation accepts `.xlsx` but the parser is `fgetcsv`). Policy management. Third-party/vendor risk. BCM / operational resilience. A compliance obligations register (circulars are tracked; there is no obligation → control → evidence chain). Internal audit management. A KRI breach register (breaches are computed nightly and survive only as notifications — no breach history, acknowledgement or MTTR). Risk acceptance with expiry. Model risk management. Board pack assembly. External loss data benchmarking. Emerging risk (mocked). Dashboard configurability (`risk/dashboard.blade.php` is a single 48.6 KB fixed layout). Async job processing (`app/Jobs/` does not exist; **Monte Carlo runs synchronously inside the HTTP request**). Meaningful test coverage (one 30 KB feature test; **no unit tests on `RiskScoringService`, `ControlEffectivenessService`, `MonteCarloService` or `RegulatoryThresholdService` — the four places where the numbers are produced**).

---

# 2. What Corporater actually is

## 2.1 The published feature set (54 items — our parity baseline)

This is the union of the web page's 46 features and the solution brief's list, which differ. Eight items appear only in the brief (automated workflows, business continuity planning, data integration, digital twin configuration, risk heat maps, risk reporting, strategic risk management, third-party risk management) and are included below.

Access control/permissions · Alerts & notifications · Alignment with strategic initiatives, objectives & performance goals · Audit log · Automated risk reporting · Automated workflows · Business continuity planning · Control activities · Customisable branding · Data integration (automated and manual input) · Data visualisation · **Digital twin configuration** · Document management · Holistic integrated governance/management/assurance (AML, Basel & Solvency, InfoSec & Data Privacy) · Improvement database · Incident management incl. loss/near-loss events · Integration with underlying risks and information systems · Intuitive user interface · KRI catalog · KRIs, KPIs and other metrics · Operational risk governance per compliance standards and taxonomies · Opportunity **and** consequence heat maps · Policy management for operational risk policies · RegTech connectors · Risk analysis (scenario, correlation, sensitivity) · Risk analysis templates · Risk appetite · **Risk assessment perspectives** (asset risk, process risk, external risk, operational scenarios, legal risk, **treasury risk**) · Risk assessments of internal and external risks (qualitative and quantitative) · Risk-bearing capability · Risk consolidation and aggregation from internal and external sources · Risk context · RCSA · Risk dashboards · Risk data upload via integration and manual input · Risk evaluation · **Risk framework support (ISO 31000, COSO, ISO 27005)** · Risk heat maps · Risk identification (manual and bulk import) based on taxonomy · Risk mitigation workflows · Risk modeling · Risk monitoring · **Risk probability distribution and simulation incl. Monte Carlo** · Risk radar · Risk register · Risk reporting · Risk response · **Risk taxonomies** · Risk treatment · SSO · **Strategic risk management** · Surveys · **Third-party risk management** · Version control

## 2.2 What the screenshots tell us — the UX model to mirror

The 12 product screenshots are more informative than the feature list. Read carefully, they reveal the whole navigation and data model.

**Global chrome.** Top bar: hamburger → **BUSINESS HQ** → **MY RESPONSIBILITIES** → global search → **a period selector reading "December 2020" with ‹ › arrows** → user avatar. Breadcrumb below: `Business HQ › Enterprise Risk Management` or `Business HQ › Risk Assessment › Assets`.

**The period selector is the single most important control on the screen.** Every dashboard, every register, every score is rendered *as at* the selected period. Step back a month and the heat map, the KPI tiles and the register all change. That is the period-aware measure model made visible.

**Object-context pages with tab sets.** The ERM object page carries tabs: `Dashboard | Risk Category Dashboard | Top Risk | Financial Risk | Credit Risk | Risk Effectiveness | Risk Register | Reports`. A *Risk Assessment* object ("Assets", "Scenarios") carries: `Dashboard | Assets | Risk Treatment Overview | Risk Development Over Time | Risk Register | Trends | Report`. Same shell, different bound object.

**"Risk assessment perspectives" are assessment contexts, and they are objects.** The register's `LOCATION` column reads `AML Risk Assessment` and `BASEL Risk Assessment`; risk IDs read `AML-027`, `BSL2-01`, `SOD019`. So an assessment is a scoped exercise with its own ID series, and a risk belongs to an assessment context *and* an organization node. The `Assets` perspective assesses risks against asset objects (Environmental Data, SaaS Customer Data, CRM System, Windpark Alpha, Customer Case and Payments System); the `Scenarios` perspective assesses risks against scenario objects typed by **Basel Level-1 event categories** (Internal Fraud; Clients, Products and Business Practice; Employment Practices and Workplace Safety; Damage to Physical Assets; Business Disruption and Systems Failures; Execution, Delivery and Process Management).

**The three-value risk model.** Charts consistently show **Inherent · Residual (current) · Future/Planned residual** side by side — per risk and per asset. Not two values. The third is what the treatment plan is *expected* to deliver, which makes treatment effectiveness measurable.

**Widgets, everywhere, with a consistent affordance.** Every panel has a `⋮` menu; registers additionally have `+` (add), 🔍 (search) and ✏️ (edit) inline. Panels are: KPI tiles (`RISK TREATMENT ACTIVITIES COMPLETED 78.0%`, `# CONTROLS NOT IMPLEMENTED 3`), registers, heat maps, charts. All are the same primitive bound to different queries.

**Heat maps carry counts in cells and come in two polarities.** Probability × Consequence 5×5 with a number badge in each occupied cell. The Scenarios page shows **AGGREGATED SCENARIO OPPORTUNITIES** (red/amber/green) *beside* **AGGREGATED SCENARIO RISKS** (blue palette, axis reversed) — ISO 31000's bidirectional definition of risk implemented literally. We have no opportunity model at all.

**The analytics vocabulary to match.**
- `RISK PER ORGANIZATIONAL UNIT` — stacked bars by **five** bands (Deep Red / Red / Orange / Amber / Green), not four
- `PARETO: TOP 50 SEMI QUALITATIVE RISK VALUE` — bars + cumulative % line
- `HIGHEST FINANCIAL RISKS (PARETO)` with an explicit **Drill Down** link
- `EBITDA / FCF / NPV` KPI tiles above **tornado-style distribution bars per year (Y1/Y2/Y3)** — Monte Carlo output projected onto financial-statement KPIs, not onto an abstract loss number
- `RISK CONTROL EFFECTIVENESS` — bubble chart, x = Internal Control / Management Effectiveness, y = Exposure (Likelihood × Consequence), bubble size = magnitude
- `RISK DEVELOPMENT OVER TIME (BY PRIORITY)` — stacked area across 12 months
- `RISK TREND` and `RISK CONTROL EFFECTIVENESS TRENDS` — quarterly stacked bars split Increasing / Constant / Decreasing
- `INHERENT VS CURRENT RESIDUAL RISK` and `INHERENT VS RESIDUAL VS PLANNED (PER ASSET)`
- `CONTROL MEASURES IMPLEMENTED` — cumulative % line over months
- `# ASSET PER TYPE`, `# GLOBAL ASSETS PER PRIORITY` — donut and bar

**Treatment overview is a four-panel pattern**: `OFF TRACK` and `ON TRACK` activity tables (name, responsible, start, end, progress bar, RAG status) beside `NOT IMPLEMENTED / FINDINGS` and `CONTROLS` tables. Controls are **typed as `Policy | Process | Asset | Supporting Document`** — controls are not a separate species, they are governance objects of several types with an `implemented` flag and a RAG status.

**Registers are inline-editable, paginated, with per-row `⋮`.** Asset register columns: Name · Responsible · Priority (shape+colour glyph) · Asset Type · Controls Implemented % · Actions Implemented %. Risk register columns: Risk ID · Name · Organization · Location · Risk Owner.

## 2.3 The architecture beneath it

1. **A base object type** with universal behaviour: identity, name/description, owner, lifecycle state, workflow attachment, permissions, audit trail, attachments, comments, and **typed relationships to other objects**.
2. **Specialised types** (`Risk`, `Control`, `KPI`, `Objective`, `Policy`, `Incident`, `Audit`, `Asset`, `Process`, `Vendor`, `Obligation`, `Action`, `Scenario`, `Plan`) inheriting from it.
3. **Metadata, not schema.** Adding a field is a metadata write, not a DDL migration.
4. **Relationship types are first-class and configurable** — `mitigates`, `owns`, `supports`, `depends-on`, `reports-to`, `maps-to`. This is what makes multi-framework mapping configuration rather than build.
5. **A BPMN 2.0 process engine**, an embedded **ETL Transformer** (REST, SQL, MDX, Excel, CSV, XML, SFTP, JDBC, scheduled), RBAC + AD integration, and SaaS / on-prem / customer-cloud deployment.
6. **A solution = object type definitions + relationship definitions + workflows + dashboards + starter content.** ERM, Internal Audit and TPRM are three configuration packages over one engine.

## 2.4 Ten capabilities the benefits prose claims that the feature list does not

The feature list is a checklist. The *Solution Benefits* narrative claims things the checklist never names, and several are substantive. These are now scored separately in the parity sheet and added to the scorecard as items 343–350.

| Claim in the prose | What it actually requires | Our state |
|---|---|---|
| *"Link risks to assurance frameworks across the **three lines of defense**"* | An assurance map: which line assures which risk and control, with coverage and gap detection | Absent → **WP-24** |
| *"correlate the corporate risk profile, impacts, and **risk maturity** with business performance and strategic objectives"* | Maturity as a scored, evidenced, period-trended assessment against COSO/ISO principles — not a label | Absent → **WP-11, WP-27** |
| *"track risk treatments and controls across your **projects, portfolios**, departments, business units, divisions, regions, and countries"* | Project and Portfolio as first-class risk-bearing node types in the graph, with their own roll-up | Absent → **WP-03, WP-27** |
| *"risk avoidance, risk reduction, **risk-sharing**, or transfer"* | Four responses, not three. **Sharing** — joint venture, consortium, co-insurance, syndication — is distinct from transfer: the risk stays partly yours and has a named counterparty | Partial. `treatment_plans.strategy` is a free string (avoid/mitigate/transfer/accept) with no sharing model → **WP-10** |
| *"**automated aggregation of risks up the chain**"* | Roll-up derived from the graph, for any measure, at any node | Partial. `Risk::calculateRollUpScore()` is risk-specific and hand-rolled → **WP-03, WP-04** |
| *"a shared, dynamic risk register for any type of risk — **from the operational to the strategic level**"* | One register, type-specific attributes. Strategic risks need horizon, objective linkage and scenario framing that operational risks do not | Partial. `risk_level` enum exists; strategic risks carry no distinct attributes → **WP-10** |
| *"Manage KRIs against their set tolerance **in the context of your strategic business objectives**"* | KRI-to-objective linkage, distinct from risk-to-objective. "This KRI is breaching, and here is the objective it threatens" | Absent → **WP-27** |
| *"a standardized reporting process based on concrete **responsibilities, periodicity, thresholds, and reporting formats**"* | A reporting-**governance** object, not a template library: who owes which report, how often, at what threshold, in what format — with evidence of delivery | Absent → **WP-12** |
| *"**Evidence that your organization has sufficient resources (people and technology)** available for proactive risk management"* | Risk-function resource adequacy as a tracked, evidenced item. Regulators ask this at examination; essentially nobody models it | Absent → **WP-24** |
| *"can be extended to **compliance management, incident management, performance management, or internal audit management, all within the same system**"* | The integrated-GRC promise — only deliverable on the object graph. This is our architecture bet restated as a customer benefit, which is the strongest possible confirmation it is the right bet | Absent → **WP-03, WP-05** |

**The treasury risk perspective** deserves a separate note. The web page lists it among the assessment perspectives; the brief does not. It matters disproportionately for us: IRRBB, liquidity, FX and counterparty risk are Pillar 2 risk types in the CBN ICAAP structure we have **already modelled**. Adding a treasury/ALM assessment perspective is a short build on top of existing capital machinery, and it is directly saleable to every Nigerian bank and the recapitalised insurers.

## 2.5 Where Corporater is weak — our attack surface

| Weakness | Our play |
|---|---|
| **Configurability without guardrails** — long time-to-value, dependency on skilled configurers (the classic Archer trap) | Ship opinionated, pre-configured Nigerian sector packs. Time-to-first-board-report measured in days. |
| **No connector marketplace** — embedded pull-only ETL; no click-to-connect for the systems enterprises actually run | API-first + a connector library targeted at what Nigerian institutions run (Finacle, Flexcube, T24, BankOne, Remita, NIBSS, Interswitch, SAP, Microsoft 365, Entra ID) |
| **No continuous control monitoring** — Forrester says this is where the market is heading; it is immature everywhere | Build CCM properly: scripted tests against source systems, full-population testing, auto-exception into the issue register |
| **Quantification stops at Monte Carlo** — no public evidence of FAIR, loss exceedance curves, LDA fitting or capital modelling | Climb to level 5–6. This is our existing strength and the clearest white space in the market. |
| **Thin third-party risk** — no tiering models, no contract lifecycle, no nth-party mapping | Build TPRM to DORA-grade with agent-network modelling for Nigeria |
| **Thin public documentation, no ecosystem** | Publish an OpenAPI spec, developer docs and an MCP server |
| **No environment fit for Africa** — no offline, no USSD/WhatsApp, no naira pricing, no local residency | The entire Phase 3 |

---

# 3. Global best-practice benchmark — what "world class" requires

## 3.1 The quantification maturity ladder

| Level | Method | Output | Who's there |
|---|---|---|---|
| 0 | RAG traffic lights | Colour | Spreadsheets |
| 1 | Ordinal 5×5 heat map | Score, position | Most of the market |
| 2 | Weighted/calibrated scales, appetite bands | Comparable index | Better mid-market |
| 3 | Semi-quantitative currency ranges, expected loss | Point estimate | LogicManager, Protecht, Camms |
| 4 | Monte Carlo on single risks, PERT inputs | Distribution, percentiles | Corporater, Archer Insight — **and us today** |
| 5 | **FAIR-conformant, loss exceedance curves, control ROI** | LEC, ALE, VaR/CVaR | RiskLens, Kovrr, Safe, MetricStream CRQ |
| 6 | **Portfolio aggregation with correlation/copulas, capital allocation, reverse stress testing** | Enterprise loss distribution, optimal control portfolio | Bank internal models; essentially no GRC platform |

**Levels 5–6 inside a general ERM platform is the clearest white space in the market.** We are at level 4 with the ICAAP scaffolding already built. This is the shortest path to a defensible technical lead.

## 3.2 Standards a world-class product must implement natively

**Core risk:** ISO 31000:2018 (scope/context/criteria → identification → analysis → evaluation → treatment → monitoring → recording & reporting, plus communication & consultation running throughout; 8 principles; framework clauses), COSO ERM 2017 (5 components, 20 principles), COSO Internal Control 2013 (5 components, 17 principles), Three Lines Model (IIA 2020).

**Information security:** ISO 27005, ISO 27001:2022 Annex A (93 controls, 4 themes), NIST CSF 2.0 (6 functions incl. Govern), NIST 800-30, **FAIR** (TEF, Vulnerability, Primary Loss, SLEF, SLM, six loss forms) and FAIR-CAM.

**Management systems:** ISO 22301 (BCM), ISO 37301 (compliance), ISO 37001 (anti-bribery), ISO 14001, ISO 45001, ISO 9001, **ISO 42001 (AI management)**.

**Banking:** Basel II/III operational risk — 7 Level-1 event types with the full Level-2/3 tree and 8 business lines, SMA (BI/BIC/ILM/ORC), loss data collection standards, boundary events, timing losses, rapidly recovered losses; ICAAP/ILAAP; stress and reverse stress testing.

**Insurance:** Solvency II / ORSA, SCR modules.

**Sustainability:** TCFD, **ISSB IFRS S1/S2**, GRI, SASB, CSRD/ESRS with XBRL tagging, EU Taxonomy.

**Resilience:** UK PRA/FCA operational resilience (important business services, impact tolerances, severe-but-plausible scenario testing, self-assessment), EU **DORA** (CIF mapping, ICT incident classification, Register of Information), APRA CPS 230.

**Analytical methods:** bow-tie with barriers and escalation factors, FMEA/FMECA with AIAG-VDA Action Priority, HAZOP, fault tree / event tree, 5 Whys and fishbone, Delphi, structured expert elicitation with calibration training, Monte Carlo, VaR/CVaR, LDA with EVT/POT tails.

## 3.3 The 2026 leading edge

- **AI grounded on a typed graph**, not a document store — risk identification from documents with confident de-duplication and linking; bidirectional confidence-scored control-to-framework auto-mapping with a human review queue; obligation-level regulatory diffing; NL query resolving against the object graph **with permission enforcement and a citation trail**; agentic workflows where the agents are themselves governed objects with declared scope, approval thresholds, action logs and kill switches.
- **AI-assisted RCSA** — pre-populate from prior period + incident history + control tests + KRI trends; flag inconsistent scoring across peer units; detect boilerplate. *This attacks the single biggest quality problem in ERM and almost nobody does it well.*
- **Continuous control monitoring** — scripted tests against source systems, full-population testing, auto-exception, control health as a continuous time series feeding residual risk without human intervention.
- **Operational resilience** — IBS → impact tolerance → dependency map → scenario test → vulnerability → remediation, with the dependency map refreshed live from CMDB and vendor registers.
- **API-first** — REST + GraphQL over 100% of the object model, webhooks, OAuth 2.0/OIDC, SCIM, OpenAPI, sandbox, versioning, **and an MCP server so the platform is queryable by external AI agents**.
- **Configuration as a versioned, promotable artefact** — dev → test → prod with diff and rollback. *This is where most "configurable" platforms fall down.*
- **The lightweight first-line experience** — Archer Engage exists because the main UI is too heavy for occasional users. **The hardest problem in ERM software is not the risk manager's UX; it is first-line participation.**

---

# 4. Nigeria and Africa — the reality to build for

## 4.1 Regulatory requirements that become product features

### Nigeria — banking and financial services
- **Recapitalisation and SREP.** ₦500bn / ₦200bn / ₦50bn minimums by 31 March 2026; capital base defined as **share capital + share premium only**, not shareholders' funds. Post-deadline, SREP capital add-ons are tied to risk-management quality — which converts an ERM purchase from a compliance cost into a capital argument.
- **Basel II/III on national discretion** — CBN Guidelines on Regulatory Capital (September 2021) plus BSD/DIR/PUB/14/063; capital conservation buffer, leverage ratio, LCR/NSFR and D-SIB surcharges introduced by CBN guideline rather than wholesale BCBS adoption.
- **ICAAP as a first-class object, not a report** — risk appetite framework, per-risk-type Pillar 2 assessment (concentration, IRRBB, business/strategic, reputational, country/transfer, model, environmental & social), stress scenarios with management actions, internal audit findings, board resolution capture, **end-of-April annual deadline workflow** with version and attestation trail.
- **Cyber returns calendar out of the box** — 24-hour incident notification to Director BSD with a pre-formatted notification, **28 February annual CSAT submission**, **monthly CTI report by the 5th**, quarterly board pack. Escalating workflow, not a static calendar entry.
- **The CBN-mandated org structure, modelled** — CISO reporting to MD/CEO (explicitly *not* to CRO or Head of IT), separate Board Risk and Board Audit committees, ISSC chaired by MD/CEO meeting quarterly, CRO at ≥ AGM grade. Access control and report routing should reflect this.
- **AML/CFT** — CBN AML/CFT Regulations, NFIU STR (24 hours) and CTR reporting, SCUML.
- **Data residency** — CBN Circular PSS/DIR/PUB/CIR/001/004 (15 June 2026): all Nigerian payment transaction data stored and managed in Nigeria by **1 January 2027**.
- **NDPA 2023 + GAID 2025** — 72-hour breach clock with countdown, Schedule 4 DPIA as a native form, DPIA trigger detection by processing category, Compliance Audit Return with a **31 March** deadline and DPCO workflow, DPO CPD tracker (40 hrs/yr), semi-annual management report, records of processing, cross-border transfer register with legal basis.
- **FRC calendar** — NCCG compliance report by **31 March**, financial statements within 60 days of board approval, qualified reports within 30 days.
- **Multi-entity, multi-licence modelling.** A Nigerian financial holding company may run a commercial bank, a PSB, a PSSP, a SEC-regulated asset manager and a NAICOM-regulated insurer — each with different frameworks, capital rules, filing calendars and regulators. Group consolidation with per-entity regulatory overlays is table stakes.

### Nigeria — other sectors
- **NAICOM** — ~43 insurers recapitalised 31 July 2026; risk-based capital framework next, **no incumbent software**. Build the ORSA-equivalent now, modelling capital adequacy on the NIIRA definition (admissible assets − liabilities − own shares), not paid-up capital.
- **PenCom** — a **monthly high-risk extract with a 7-day post-month-end deadline**. That specific cadence is a product feature, not a config option.
- **NCC, NUPRC/NMDPRA, NERC (federal *and* state), NIMASA, FIRS.**
- **Extractives** — a host-community/social risk register as a first-class module: HCDT 3% opex obligations, community grievance logging, incident tracking by host community, stakeholder engagement records. Global ERM products have "stakeholder management"; none model a Nigerian host community trust.
- **Public sector** — SGF Circular 59805 created several hundred simultaneous NDPA buyers with personal liability attached to the accounting officer. **The highest-urgency buying trigger in the Nigerian public sector right now.**

### Pan-African
Country packs, not a single "Africa" configuration. Nigeria (CBN/SEC/NAICOM/PenCom/FRC/NDPC) · **Ghana** (BoG Act 930, climate directive effective January 2026, cyber, outsourcing, NPL) · **Kenya** (CBK Risk Management Guidelines, ODPC/DPA 2019) · **South Africa** (**King V** Disclosure Framework — build disclosure statements mapped to each *recommended practice* across all 13 principles; products still modelling King IV's 17 principles will look stale to SA boards from FY2026 — plus Prudential Authority, POPIA).

**Climate risk as a native risk category, not an ESG bolt-on.** Ghana mandates it for banks from January 2026; Nigeria's FRC roadmap requires scenario analysis models and an ERM framework at Stage 3; South Africa's PA is signalling mandatory. Requires: physical and transition risk taxonomy, scenario analysis in quantitative and qualitative modes, Scope 1/2/3 GHG capture with the year-one Scope 3 relief flag, climate targets with performance tracking, and links from climate risks into the main register.

**Assurance-readiness mode.** Nigeria's assurance ladder escalates to reasonable assurance by Year 7 under ISSA 5000 — sustainability data needs audit-grade lineage, evidence attachment and immutability from day one.

## 4.2 The environment requirements — the actual moat

| Reality | Product requirement |
|---|---|
| ~190m mobile subscriptions but only ~110m broadband; branch staff, agents and field officers on 2G/3G, on metered data they pay for personally | **Offline-first is the architecture, not a feature.** Local-first data model, deterministic conflict resolution, background sync, resumable uploads. A user must be able to open a register, log a loss event, complete an RCSA and attach a photo with no connectivity. Test on 2G with 40% packet loss, not office Wi-Fi. |
| National grid collapsed again in 2026; >2,500MW wasted to grid unreliability; ~70% of businesses on generators | **Battery- and session-resilient UX.** Autosave every field. Never lose a half-completed assessment to a power cut. |
| USSD works without data or a smartphone; WhatsApp banking is launching actively in Nigeria | **USSD and WhatsApp capture channels for front-line data.** A branch teller or agent should report a loss event or control failure by short code or WhatsApp, parsed into a structured incident record. **No global vendor does this, and it is the only way to get real event data out of a 40,000-agent network.** |
| Inflation 15.91% (June 2026), down from 33%+ peaks; CBN rate ~₦1,365.69/USD (7 Aug 2026) with a parallel spread | **Inflation-indexed thresholds.** Appetite and loss-materiality thresholds defined as a **formula** (% of capital, % of revenue, CPI-indexed), with an automatic annual re-baselining prompt and full audit trail. This directly fixes the "everything is High" drift that ruins Nigerian risk registers. **Naira-native with multi-currency and dual-rate handling** — store amount *with* currency and the rate applied, label official vs parallel explicitly, allow group reporting in USD with per-entity local currency. |
| CBN localisation from 1 Jan 2027; NITDA National Cloud Policy 2025 | **Deployment flexibility as a first-class SKU** — Nigeria-region cloud, Nigerian-partner private cloud, genuine on-premise. Publish a data-residency attestation. Price all three. |
| A $150k/yr USD licence at ₦1,365/USD is ~₦205m/yr and re-prices every time the naira moves | **Naira pricing, locally invoiced, local payment rails, flat or entity-based tiers** — not per-seat. Per-seat pricing kills the front-line data capture that makes the product valuable. Reference point: Pirani at $276/month flat. |
| Risk maturity is the constraint, not software features. Outside tier-1 banks, spreadsheets are the norm | **Ship starter content, not an empty tool** — pre-loaded Nigerian risk taxonomies by sector, CBN/SEC/NAICOM/PenCom/NDPC obligation libraries, RCSA templates, KRI libraries, board report templates. **This is the number-one reason global tools get bought and abandoned.** Plus in-product guidance, certification and a local implementation team. |
| BPP/NOCOPO tendering, 9–18 month cycles | Tender-ready documentation, local entity, tax clearance, local support presence, references. |
| English is the business language; French matters for UEMOA/CEMAC | **French UI before any Nigerian-language UI.** Local languages belong on the USSD/WhatsApp capture layer, not the platform. |

## 4.3 The Nigerian risk taxonomy that no global product models

Insecurity, banditry and kidnapping (with location and route dimensions) · Power / diesel / energy supply risk · FX and repatriation risk (official vs parallel) · Regulatory volatility · Third-party agent networks (per-agent exclusivity, transaction limits, principal liability under the October 2025 Agent Banking Guidelines) · Cash handling and cash-in-transit · Host-community and herder/farmer conflict risk · Fuel supply risk · Channel-level fraud typologies per NIBSS data.

## 4.4 Competitive read

The top of the Nigerian market is served by global platforms bought under regulatory pressure and typically underused. The middle and bottom run on Excel. The regional incumbent (**BarnOwl**, South Africa) is SA-focused. The most credible challenger positioning is being demonstrated by **Pirani** (Colombia) at $276/month flat, publishing CBN/BoG/PA-specific regulatory guides. **There is no established Nigerian-built ERM platform with meaningful public presence.**

**Strategy:** do not fight MetricStream/Archer for tier-1 banks head-on. Win the mid-market — mid-tier banks, the recapitalised insurers, PFAs, PSPs, listed corporates in the ₦30bn+ PIE band, MDAs under SGF Circular 59805. **Compete on regulatory specificity, not feature count.** The winning claim is *"ships with CBN, SEC, NAICOM, PenCom, NDPC and FRC obligation libraries and filing calendars pre-loaded"* — not "highly configurable." Global vendors cannot economically maintain Nigerian regulatory content; that is the durable moat. Partner with Big Four and local risk advisory firms rather than compete — they own the buying relationships.

---

# 5. Gap analysis

The full scorecard is in **`ERM_Gap_Analysis_Scorecard.xlsx`** — **350 capability items across 20 domains**, each scored:

- **Current state:** `Absent` · `Mock` · `Partial` · `Present` · `Strong`
- **Class:** `[TS]` Table stakes (130 items) · `[D]` Differentiator (163) · `[LE]` Leading edge (57)
- **Corporater:** does the benchmark have it
- **Priority:** `P0` blocker (16) · `P1` parity (82) · `P2` moat (145) · `P3` beyond (107)
- **Work package:** which WP delivers it

Two coverage figures are reported. **Strict coverage** counts only `Strong` + `Present`. **Weighted coverage** scores `Strong` 1.00, `Present` 0.85, `Partial` 0.40, `Mock` 0.05, `Absent` 0.00 — `Mock` is deliberately scored below `Partial` because a fabricated number is worse than a missing one.

## 5.1 Headline scores by domain

| # | Domain | Items | Weighted |
|---|---|---|---|
| A | Platform architecture & data model | 25 | **11.2%** |
| B | Risk taxonomy, register & appetite | 22 | 33.0% |
| C | Assessment, analysis & methodology | 23 | 24.8% |
| D | Risk quantification | 18 | 23.6% |
| E | Controls & control monitoring | 20 | 22.5% |
| F | Compliance, obligations & regulatory change | 18 | 11.7% |
| G | Policy management | 10 | **0%** |
| H | Internal audit & assurance | 18 | 9.2% |
| I | Incidents, events, issues & actions | 16 | **65.9%** ← our strongest |
| J | Third-party & supply chain risk | 19 | **0%** |
| K | Operational resilience & BCM | 17 | **0%** |
| L | ESG & sustainability | 12 | **0%** |
| M | Strategy & performance | 14 | 2.9% |
| N | Reporting, dashboards & analytics | 17 | 9.4% |
| O | AI & intelligent automation | 18 | 9.7% |
| P | Integration & data | 16 | 2.5% |
| Q | UX, configurability & accessibility | 16 | 2.5% |
| R | Security, administration & platform governance | 17 | 11.8% |
| S | Deployment, delivery & commercial | 10 | 8.0% |
| T | **Nigeria & Africa environment (our own domain)** | 24 | 9.2% |
| — | **TOTAL** | **350** | **13.8%** |

**Weighted coverage against the world-class benchmark: 13.8%** (strict: 8.3%).
**Weighted parity against Corporater: 38.2%** across **64 items** — the 54 published features plus the 10 capabilities their benefits prose claims but the feature list omits (§2.4). Of those 64: 15 Strong/Present, 28 Partial, 21 Absent/Mock. Scored against the published feature list alone it is 43.1%; the prose is where the harder claims live.

By capability class: **table stakes ~19% covered · differentiators ~2% · leading edge 0%.**

Read that carefully. We are not a weak product — the strict number understates us badly, because 62 items sit at `Partial` where the schema is right and the behaviour or UI is missing. The pattern is consistent and diagnostic: **the data model is repeatedly ahead of the application.** The questionnaire schema is ahead of the market with a 915-byte UI. The workflow table models escalation rules that are never read. The quantification tables model copulas and stress multipliers that are never applied. `entities`/`entity_types` is a digital twin with no attribute schema. Thirteen configuration surfaces are written and read by nothing.

That is good news. It means the remodel is mostly about **building the engine layer the schema already assumes**, not about rewriting the schema. It is also exactly why the object graph and period model come first: they are the two engines the existing tables have been waiting for.

## 5.2 The seven gaps that matter most

Ranked by *(strategic value × competitive defensibility) ÷ effort*.

| Rank | Gap | Why it is #n |
|---|---|---|
| **1** | **No metadata-driven object graph / digital twin** | Everything else is 3–5× more expensive without it. Corporater's entire cost advantage lives here. We have the seed (`entities`/`entity_types`) — this is a 10-week upgrade, not a rewrite. |
| **2** | **No period model** | Without it there is no trend, no "as at", no defensible point-in-time record, no animated heat map, no assessment cycle, no board pack that reconciles. Every screenshot Corporater publishes depends on the period selector. |
| **3** | **No configurable dashboards / context-bound widgets** | We have one 48.6 KB fixed dashboard. Corporater has one widget primitive and N contexts. This is what a buyer sees in the first ten minutes of a demo. |
| **4** | **No first-line experience ("My Responsibilities" + mobile + offline)** | The hardest problem in ERM software is first-line participation, not risk-manager UX. In Nigeria it is also a connectivity and power problem. Solving it is our single highest-leverage adoption feature **and** an environment moat global vendors will not copy. |
| **5** | **No API, no integration layer, no async processing** | Blocks every integration claim, blocks the connector story, blocks the MCP/AI story, and Monte Carlo runs synchronously in a web request today. |
| **6** | **Quantification stops at level 4** | We are already at level 4 with ICAAP scaffolding. Levels 5–6 are the clearest white space in the global market and the shortest path to a defensible technical lead. |
| **7** | **No PDF, no board pack, fabricated AI** | A board report that is a CSV is not a board report. Fabricated AI metrics are a reputational and regulatory event waiting to happen. Both are cheap to fix and both are demo-blockers. |

---

# 6. Target architecture

## 6.1 The layer model

```
┌──────────────────────────────────────────────────────────────────────────┐
│  CHANNELS   Web (PWA, offline-first) · Mobile · USSD · WhatsApp ·        │
│             Email intake · REST/GraphQL API · Webhooks · MCP server       │
├──────────────────────────────────────────────────────────────────────────┤
│  EXPERIENCE Business HQ (per-node landing) · My Responsibilities         │
│             Context-bound widget dashboards · Registers · Analytics      │
│             Report Studio (PDF/XLSX/PPTX/DOCX) · Board pack assembly     │
├──────────────────────────────────────────────────────────────────────────┤
│  SOLUTIONS  ERM · Operational Risk · Internal Control · Compliance ·     │
│  (config    Policy · Internal Audit · TPRM · Resilience/BCM · ESG &      │
│   packages) Climate · AI Governance · Strategy & Performance             │
│             ── each = object types + relationships + workflows +         │
│                dashboards + starter content, NOT a codebase ──           │
├──────────────────────────────────────────────────────────────────────────┤
│  DOMAIN     Scoring engine · Control effectiveness · Appetite engine ·   │
│  SERVICES   Quantification (MC/FAIR/LEC/LDA/copula) · Regulatory         │
│             threshold engine · Escalation · Assurance mapping            │
├──────────────────────────────────────────────────────────────────────────┤
│  PLATFORM   ① Object Graph Core   ② Period & Measure Engine              │
│  CORE       ③ Metadata/Config Engine  ④ Workflow Engine v2               │
│             ⑤ Widget/Dashboard Engine ⑥ Security & Tenancy Kernel        │
│             ⑦ Event Bus & Outbox   ⑧ Job/Queue Layer                     │
├──────────────────────────────────────────────────────────────────────────┤
│  DATA       MySQL/Postgres · Redis · S3-compatible object store ·        │
│             Search index · Local-first client store (IndexedDB)          │
└──────────────────────────────────────────────────────────────────────────┘
```

## 6.2 Platform core ① — the Object Graph (the digital twin)

**Concept.** Every governed thing in the system is a **node** in one typed graph. Organisation units, processes, systems, assets, products, geographies, vendors, projects, important business services *and* the governance artefacts attached to them (risks, controls, KRIs, policies, obligations, issues, incidents, actions, audits) are all nodes of different **object types**, connected by typed **relationships**.

**New tables.**

```
object_types            id, organization_id, code, name, plural_name, category
                        (org_node | governance | assessment | reference),
                        parent_type_id (inheritance), icon, color, level_hint,
                        is_system, is_node_type, allowed_child_type_ids (json),
                        default_lifecycle_id, code_prefix, sort_order

object_attributes       id, object_type_id, code, label, data_type
                        (string|text|int|decimal|money|bool|date|datetime|
                         enum|multi_enum|user|object_ref|json|formula),
                        is_required, is_unique, default_value, validation (json),
                        enum_options (json), ref_object_type_id, formula,
                        section, sort_order, help_text, is_pii, is_system

objects                 id, organization_id, object_type_id, node_id (owning
                        org-graph node), code, name, description, owner_id,
                        delegate_owner_id, lifecycle_state, status,
                        effective_from, effective_to, attributes (json),
                        search_vector, created_by, updated_by, version,
                        deleted_at

object_relationship_types  id, organization_id, code (mitigates|owns|supports|
                        depends_on|maps_to|assures|reports_to|assessed_in|
                        threatens|derives_from), name, inverse_code,
                        from_type_ids (json), to_type_ids (json),
                        cardinality, has_weight, has_attributes,
                        attribute_schema (json)

object_relationships    id, organization_id, relationship_type_id,
                        from_object_id, to_object_id, weight,
                        attributes (json), effective_from, effective_to,
                        created_by
                        UNIQUE (relationship_type_id, from_object_id, to_object_id)

object_lifecycles       id, object_type_id, code, name, states (json:
                        [{code,name,color,is_initial,is_terminal,
                          allowed_transitions:[],required_permission,
                          required_workflow_id}])

object_versions         id, object_id, version, snapshot (json), changed_by,
                        changed_at, change_reason, source (ui|api|import|job)
```

**Migration strategy — additive, never destructive.**
1. Create the metadata tables and seed `object_types` for every existing domain model (`Risk`, `Control`, `KeyRiskIndicator`, `Issue`, `LossEvent`, `TreatmentPlan`, `RiskAppetite`, `AssessmentCampaign`, …) plus the org-node types (`Enterprise`, `LegalEntity`, `Division`, `BusinessUnit`, `Department`, `Process`, `System`, `Asset`, `Product`, `Geography`, `Vendor`, `Project`, `ImportantBusinessService`).
2. **Unify the two org models.** Migrate `business_units` and `business_processes` into `objects` as nodes of type `BusinessUnit` / `Process`, merged with the existing `entities` tree. Keep `business_unit_id` as a **generated/derived column or view** during transition so nothing breaks. Deprecate on a schedule.
3. Introduce a `HasObjectIdentity` trait on existing Eloquent models that maintains a mirrored `objects` row (`object_type_id`, `node_id`, `code`, `name`, `owner_id`, `lifecycle_state`) via model events. **Existing tables keep their typed columns** — the `objects` row is the graph index, not a replacement. This is the key to evolving in place.
4. Custom fields go into `objects.attributes` (json) with schema and validation from `object_attributes`, surfaced through a dynamic form renderer.
5. Migrate existing pivots (`risk_control_mapping`, `risk_related_risks`, `loss_event_controls`, `regulatory_risk_mapping`, `risk_kri_mapping`) into `object_relationships` with typed relationship codes, keeping the old tables as views during transition.

**What this immediately unlocks:** roll-up and aggregation from the graph instead of hardcoded queries · permissioning by subtree · "Business HQ" pages for any node · cross-domain queries · a coherent grounding surface for AI · custom object types per customer without a release.

## 6.3 Platform core ② — the Period & Measure Engine

**Concept.** `(measure × object × period × scenario) → value`. KRIs, KPIs, risk scores, control effectiveness, ESG metrics and financial figures all become measures. This is the single change that produces trends, "as at" queries, assessment cycles and a board pack that reconciles.

```
periods            id, organization_id, calendar_id, code (2026-M08, 2026-Q3,
                   FY2026), type (day|week|month|quarter|half|year|custom),
                   name, start_date, end_date, parent_period_id, is_closed,
                   closed_at, closed_by

period_calendars   id, organization_id, code, name, fiscal_year_start_month,
                   is_default

measures           id, organization_id, code, name, object_type_id (what it
                   measures), measure_kind (kri|kpi|risk_score|control_eff|
                   esg|financial|capital), unit_id, aggregation
                   (sum|avg|last|max|min|weighted_avg), polarity
                   (higher_better|lower_better|target_band),
                   decimal_places, formula, is_derived, source
                   (manual|import|api|calculated), owner_id

measure_values     id, organization_id, measure_id, object_id, period_id,
                   scenario (actual|target|budget|forecast|stress|plan),
                   value decimal(28,6), currency_code, fx_rate_used,
                   status (draft|submitted|approved|locked), rag_band,
                   entered_by, entered_at, source, evidence_ref, note
                   UNIQUE (measure_id, object_id, period_id, scenario, currency_code)
                   INDEX (organization_id, period_id, measure_id)

measure_thresholds id, measure_id, object_id (nullable = default),
                   effective_from, bands (json: [{code,label,color,min,max}]),
                   direction, approved_by, approved_at

units              id, code, name, symbol, category (currency|count|pct|
                   time|mass|energy|custom), base_unit_id, conversion_factor

fx_rates           id, organization_id, from_currency, to_currency, rate_date,
                   rate_type (cbn_official|nafem|parallel|internal|custom),
                   rate decimal(18,8), source, captured_at
                   UNIQUE (from_currency, to_currency, rate_date, rate_type)
```

**Risk scores become measures.** Keep `risks.inherent_score` etc. as the *current* denormalised value for fast list rendering, but write every assessment outcome to `measure_values` against measures `risk.inherent_score`, `risk.residual_score`, `risk.target_score`, `risk.control_effectiveness`. Then:

- "Risk profile as at 31 December" is `WHERE period_id = :p`
- The heat map animates across periods
- `RISK DEVELOPMENT OVER TIME (BY PRIORITY)` is a group-by
- `RISK TREND (Increasing/Constant/Decreasing)` is a period-over-period delta
- The board pack is a period query, and it reconciles

**KRIs and KPIs unify.** `key_risk_indicators` becomes a `measure` with `measure_kind = kri`; `kri_measurements` migrates into `measure_values`; thresholds move into `measure_thresholds` with **effective-dated bands** (so re-baselining a threshold for inflation is a first-class, audited event). Add the missing **`measure_breaches`** table so breach history, acknowledgement, assignee and MTTR actually exist:

```
measure_breaches   id, organization_id, measure_id, object_id, period_id,
                   breached_at, band_from, band_to, value, threshold_value,
                   severity, status (open|acknowledged|resolved|false_positive),
                   acknowledged_by, acknowledged_at, resolved_at,
                   linked_risk_id, linked_issue_id, root_cause, note
```

**Inflation-indexed thresholds — the Nigerian differentiator.** `measure_thresholds.bands` supports formula-valued bounds: `"max": "0.005 * @capital.total_qualifying"` or `"max": "50000000 * @cpi_index(2026-01)"`. A scheduled job re-evaluates formula thresholds at period close, raises a **re-baselining approval task**, and writes a new effective-dated band on approval — with the old band retained. This directly fixes the "everything is High" drift.

## 6.4 Platform core ③ — Metadata / Configuration Engine

- **Object type builder** — create types, attributes, validation, calculated fields, relationships, lifecycles. No DDL, no release.
- **Form/layout builder** — sections, ordering, conditional visibility, role-conditional rendering, mobile layout variants.
- **Dynamic form renderer** driven by `object_attributes` — one Blade/Livewire component replaces the 20+ hand-rolled create/edit views.
- **Configuration as a versioned, promotable artefact.** A `config_bundles` table storing a signed JSON export of object types, attributes, relationships, lifecycles, workflows, dashboards, measures, thresholds and content packs, with import, **diff**, dry-run and rollback across dev → test → prod. *Most "configurable" platforms fail here; doing it well is a genuine differentiator.*
- **Content packs** — a versioned, installable bundle (see §6.9).
- **Retire the ignored config.** `organizations.settings->risk_settings`/`risk_thresholds` become real: matrix dimensions, scale point definitions, rating bands, impact aggregation method (max | weighted | average), residual formula — all read by a single `ScoringProfile` resolved per organisation, per business unit and per risk type.

## 6.5 Platform core ④ — Workflow Engine v2

Retire all three current mechanisms into one.

```
workflow_definitions  + version, is_published, bpmn_xml (optional),
                      definition (json: nodes[], edges[]), trigger
                      (manual|on_create|on_transition|on_schedule|on_event),
                      trigger_config (json), object_type_id, scope_filter (json)

workflow_nodes        (in json) type: start | task | approval | parallel_gateway
                      | exclusive_gateway | timer | service_task | sub_process
                      | escalation | end
                      per node: assignee_rule (user|role|owner|manager|
                      relationship_traversal|group), sla_hours, escalation_after,
                      escalate_to, on_timeout, required_fields, form_id,
                      allow_delegate, allow_return, conditions (expression)

workflow_instances    + context (json), current_nodes (json array — parallel),
                      sla_due_at, breached_at, correlation_key

workflow_tasks        id, instance_id, node_code, assignee_id, assignee_role,
                      status, due_at, escalated_at, completed_at, outcome,
                      comments, delegated_from, form_data (json)
```

Requirements: **parallel branches**, exclusive gateways with expression conditions, timers, **SLA with escalation** (finally reading `escalation_rules`), delegation and out-of-office reassignment, return-for-rework, sub-processes, and service tasks (call a job, an API, an AI agent). Every module's approval — risk assessment, treatment plan, control test, loss event, appetite, closure, policy attestation, ICAAP sign-off — runs on this engine, and `approval_requests` is migrated into it.

## 6.6 Platform core ⑤ — Widget & Dashboard Engine

The single feature that changes the demo.

```
widget_definitions   id, organization_id, code, name, widget_type
                     (kpi_tile | register | heatmap | bar | stacked_bar |
                      line | area | donut | pareto | bubble | scatter |
                      gauge | treemap | matrix | tree | timeline | gantt |
                      lec_curve | tornado | bowtie | network | text | iframe),
                     object_type_id, query (json: filters, joins, group_by,
                     aggregate, sort, limit), measure_id, period_binding
                     (selected|relative:-1|fixed|range:12), context_binding
                     (inherit_node | inherit_subtree | fixed_node | user_scope),
                     visualisation (json), drilldown (json), is_system

dashboards           id, organization_id, code, name, object_type_id
                     (which node type this dashboard serves), role_ids (json),
                     is_default_for_role, layout (json: grid of
                     {widget_id, x, y, w, h, overrides}), tabs (json),
                     is_published, version

dashboard_user_prefs id, user_id, dashboard_id, layout_override (json),
                     saved_filters (json)
```

**Context binding is the whole point.** Drop a "Top 10 Risks" widget on the Group HQ page and it shows group risks; the identical widget on a BU page shows that BU's risks. One definition, N contexts. Combine with role-based composition and the reporting requirement is largely met without a report-writing project.

**Ship these widget types in Phase 2** (they map 1:1 to the Corporater screenshots): KPI tile · register with inline actions · risk heat map with cell counts and drill-through · **opportunity heat map (inverse polarity, blue palette)** · risk-per-org-unit 5-band stacked bar · Pareto with cumulative line and drill-down · inherent/residual/planned grouped bar · control-effectiveness bubble (x = control effectiveness, y = exposure, size = magnitude) · risk-development-over-time stacked area · risk-trend quarterly stacked bar (Increasing/Constant/Decreasing) · on-track/off-track activity table with progress bars · controls-and-findings table · cumulative control-implementation line · donut by priority · bar by type.

**Follow the dataviz standards** (see the `dataviz` skill) — one visual system, accessible in light and dark, consistent categorical palette, and RAG bands that survive colour-blind rendering.

## 6.7 Platform core ⑥ — Security & Tenancy Kernel

Non-negotiable, Phase 0.

- **`BelongsToOrganization` trait with a global scope on every tenant-scoped model.** Bind the tenant from the authenticated user in middleware, store it in a container-bound `TenantContext`, and **remove every `?? 1` fallback** — a null `organization_id` must be a hard 403, never a silent read of organization 1.
- **Route-level permission middleware on all 203 routes.** Generate the permission matrix from the route list, enforce with `permission:` middleware, and add a test that fails CI if any route lacks an authorization guard.
- **Node-scoped authorization.** Permissions resolve against the object graph: a user with `risk.view` at the "Retail Banking" node sees that subtree only. Implement as an `AuthorizesAgainstGraph` policy trait using materialised `hierarchy_path` prefix matching.
- **SSO** — `laravel/socialite` + SAML 2.0 (`aacotroneo/laravel-saml2` or LightSAML) + OIDC + Microsoft Entra ID, with SCIM 2.0 user provisioning and just-in-time role mapping.
- **MFA enforced** — alias and apply `EnsureMfaVerified`; make MFA mandatory per role by policy.
- **Delete the dormant `AutoLoginDev`.** Harden `.env` handling, rotate the committed SMTP credentials, set `APP_DEBUG=false` in every non-local environment, and add a deployment check that fails on `APP_DEBUG=true`.
- **Self-host all front-end assets.** Build via Vite, drop `cdn.tailwindcss.com`, `cdn.jsdelivr.net` and Google Fonts. *The on-prem data-residency claim is not credible while every page load reaches three foreign CDNs* — and `cdn.tailwindcss.com` is explicitly not for production.
- **Field-level encryption** for PII, **immutable append-only audit log** with hash chaining, configurable retention, and a break-glass access record.

## 6.8 Platform core ⑦⑧ — Events, Jobs, Integration

- **Create `app/Jobs/`.** Move Monte Carlo, imports, exports, report generation, notification fan-out, LLM calls, connector syncs and re-baselining to queued jobs with progress, cancel and retry. Redis-backed queue with Horizon.
- **REST API** at `routes/api.php` over **100% of the object model**, Sanctum + OAuth2 client credentials, JSON:API-shaped resources, filtering/sorting/sparse fieldsets, cursor pagination, idempotency keys, rate limiting, versioning (`/api/v1`), and a generated **OpenAPI 3.1 spec**.
- **Webhooks** — subscribe to object lifecycle and domain events, signed payloads, retries with exponential backoff, a delivery log.
- **Connector framework** — a `Connector` contract with scheduled sync, credential vault, field mapping, dry-run, and per-run reconciliation. Prioritised targets for Nigeria: **core banking** (Finacle, Flexcube, T24, BankOne), **payments/switch** (NIBSS, Interswitch, Remita), **ERP/HR** (SAP, Oracle, Sage, Workday), **identity** (Entra ID, Okta, Active Directory), **ITSM** (ServiceNow, Jira, Freshservice), **security** (Splunk, Sentinel, CrowdStrike, Qualys, Tenable), **collaboration** (Microsoft 365, Teams, Slack, Google Workspace), **BI** (Power BI, Tableau).
- **MCP server** exposing the object graph as read tools plus governed write tools, so the platform is queryable by external AI agents — and by our own.

## 6.9 Content Packs — the moat, productised

A **content pack** is a versioned, installable, updatable bundle:

```
content_packs          id, code, name, publisher, version, jurisdiction,
                       sector, description, requires_version, checksum,
                       installed_at, installed_by, is_managed (auto-update)

content_pack_items     id, pack_id, item_type (object_type|attribute|taxonomy|
                       obligation|control|kri|risk_template|questionnaire|
                       workflow|dashboard|widget|report_template|
                       calendar_entry|scale|threshold_formula|mapping),
                       code, payload (json), depends_on (json)

content_pack_bindings  id, pack_item_id, local_object_id, binding_state
                       (linked|customised|detached), customisation_diff (json)
```

**Customer customisation must survive a pack upgrade** — that is the hard part and the reason to build bindings, not copies.

**Packs to ship:**

| Pack | Contents |
|---|---|
| **NG-CORE** | Nigerian risk taxonomy (incl. insecurity/kidnap with route & location dimensions, power & diesel, FX & repatriation, regulatory volatility, agent networks, cash-in-transit, host community, fuel supply), NGN currency and CBN/NAFEM/parallel FX rate types, Nigerian public holidays, NGN number and date formats |
| **NG-BANK** | CBN prudential obligations, ICAAP object and April deadline workflow, Basel L1/L2/L3 event tree + 8 business lines, CBN ORMS mapping, SREP self-assessment, cyber returns calendar (24-hour BSD notification, 28 Feb CSAT, monthly CTI by the 5th, quarterly board pack), AML/CFT + NFIU STR/CTR thresholds and clocks, NDIC, BOFIA, agent banking controls, CBN org-structure model (CISO → MD/CEO, ISSC, Board Risk / Board Audit), returns calendar (FinA, eFASS, CRMS) |
| **NG-INSURANCE** | NAICOM risk-based capital, ORSA-equivalent workpack, NIIRA capital definition (admissible assets − liabilities − own shares), solvency monitoring, NAICOM returns calendar |
| **NG-PENSION** | PenCom RMF for PFAs/PFCs, **monthly high-risk extract with 7-day post-month-end deadline**, investment limit KRIs |
| **NG-CAPMKT** | SEC Nigeria + ISA 2025 obligations, NGX listing and governance rules, capital market operator risk framework |
| **NG-DATA** | NDPA 2023 + GAID 2025 — 72-hour breach clock, Schedule 4 DPIA form, DPIA trigger detection, Compliance Audit Return with 31 March deadline, DPO CPD tracker (40 hrs/yr), semi-annual management report, ROPA, cross-border transfer register |
| **NG-PUBLIC** | SGF Circular 59805 kit for MDAs, BPP/NOCOPO procurement risk, FRC/NCCG compliance report (31 March) |
| **NG-ENERGY** | NUPRC/NMDPRA obligations, **HCDT 3% opex** host-community module with grievance logging and stakeholder engagement records, NERC (federal and state), community/security incident taxonomy |
| **NG-TELCO** | NCC obligations, licence conditions, QoS KPIs as measures |
| **GH-CORE / KE-CORE / ZA-CORE** | Bank of Ghana Act 930 + climate directive (Jan 2026) + cyber + outsourcing + NPL; CBK Risk Management Guidelines + Kenya DPA 2019; **King V Disclosure Framework** (disclosure statements mapped to each *recommended practice* across all 13 principles) + Prudential Authority + POPIA |
| **STD-ISO31000 / STD-COSO-ERM / STD-COSO-IC / STD-ISO27001-2022 / STD-ISO27005 / STD-ISO22301 / STD-ISO37301 / STD-ISO42001 / STD-NIST-CSF2 / STD-BASEL-OR / STD-IFRS-S1-S2 / STD-TCFD / STD-DORA** | Framework structures, requirement trees, control libraries, maturity rubrics, cross-mappings |

**Cross-mapping is the payoff.** Because `object_relationships` includes a `maps_to` type, one control can satisfy a CBN requirement, an ISO 27001 Annex A control and a NIST CSF subcategory simultaneously — tested once, reported three ways. That is the "common control model", and it is why the object graph has to come first.

## 6.10 Front-end architecture

- **Kill the dual stack.** Delete the CDN script tags, build everything through Vite, self-host fonts and icons.
- **Livewire 3 + Alpine** over Blade. This preserves the existing Laravel/Blade investment (the "evolve in place" decision) while enabling inline editing, reactive registers, drag-and-drop dashboards and modals — without an SPA rewrite. Add Livewire's SPA navigation for perceived speed.
- **A real design system.** One `<x-data-grid>` component replacing the 520-byte stub and the ~25 hand-rolled index views: server-side filter/sort/paginate, saved views, column chooser, bulk actions, inline edit, export, and per-row action menu (the Corporater `⋮`).
- **PWA, offline-first.** Service worker with a versioned app shell; IndexedDB local store; an outbox queue with deterministic conflict resolution (last-write-wins on scalar fields, three-way merge with a review queue on text); resumable chunked uploads; background sync; explicit sync status UI. **Autosave every field** so a power cut never costs a half-completed assessment. Performance budget: first meaningful paint < 3 s on simulated 2G, initial payload < 200 KB gzipped, list views render before charts.
- **Mobile-first layouts** for the first-line journeys: My Responsibilities, log a loss event (with camera and GPS), complete an RCSA, approve, attest, submit a KRI reading.
- **Accessibility to WCAG 2.2 AA**, full i18n scaffolding (`lang/` with en, then fr), RTL-ready, and currency/number/date formatting driven by locale — not `₦` hardcoded in 28 views.

---

# 7. Module blueprint — current → target

Legend: **●** exists and is strong · **◐** partial · **○** absent

| Module | Now | Target state |
|---|---|---|
| **Risk Register** | ● Register, hierarchy with roll-up, weighted control mapping, audit trail | Add: **strategic-risk attributes** (horizon, objective linkage, scenario framing) so one register genuinely spans operational to strategic · cause → event → consequence structure · **opportunity modelling** (ISO 31000 bidirectional) · multiple simultaneous taxonomies per risk (Basel + internal + strategic theme + ESG) · **target likelihood/impact/score as real fields** (today `target_rating` is a label only, so target risk cannot be plotted) · risk proximity and persistence · risk acceptance with expiry and re-review · emerging risk register distinct from principal · risk network/contagion visualisation · assessment-context ("perspective") scoping with its own ID series |
| **Risk Taxonomy** | ◐ `risk_categories` in use; `risk_taxonomies` a disconnected parallel tree with its own live UI | Merge into one graph-backed taxonomy service supporting **N simultaneous taxonomies**, multi-framework cross-mapping, versioned taxonomy packs, and bulk re-classification |
| **Scales & Scoring** | ◐ Hardcoded 5×5 in 3 places, two divergent rating band sets, two divergent effectiveness maps | One `ScoringProfile` resolved per org / BU / risk type: **configurable matrix dimensions** (3×3 to 10×10), named and defined scale points, configurable rating bands, impact aggregation method (max \| weighted \| average \| worst-two), configurable residual formula, and semi-quantitative currency bands per scale point |
| **Risk Appetite** | ◐ Category-level statement, one tolerance band; **service reads 5 non-existent columns** | Rewrite. Appetite as a first-class object linked to **objectives and to nodes**: appetite → tolerance → capacity tiers, cascade from group to BU limits, **currency-denominated appetite with stated probability of exceedance**, per-KRI limit linkage, automatic breach detection into `measure_breaches`, breach workflow with board escalation, effective-dated versions with approval. **Inflation-indexed formula thresholds.** |
| **RCSA / Campaigns** | ◐ Rich schema, thin UI, **`storeWorksheet()` is a stub that discards submissions** | Fix the stub first. Then: full lifecycle (scope → distribute → respond → **second-line challenge** → sign-off), prior-period pre-population with change highlighting, offline response capture, assessment quality analytics (outlier, boilerplate and peer-comparison detection), and AI-assisted drafting |
| **Questionnaires** | ◐ Schema ahead of market, UI 915 bytes | Build the UI the schema deserves: visual builder with conditional logic, scoring rules, section/question weights, preview, versioning, and a vendor/third-party portal |
| **Controls** | ● Library, weighted mapping, key-control flag | Add: **typed control objects** (`Policy \| Process \| Asset \| Supporting Document` — mirroring Corporater) · separate **design** vs **operating** effectiveness · deficiency escalation (deficiency → significant → material weakness) · owner attestation cycles · **common control model** across frameworks · control rationalisation analytics · **control coverage gap detection** (risks above appetite with no effective control) |
| **Control Testing** | ● Complete lifecycle with evidence and Gates | Add: sampling methodology · **continuous control monitoring** with scripted tests against source systems · full-population testing · auto-exception into the issue register · **control health as a continuous time series feeding residual risk automatically** · immutable evidence provenance with hashing |
| **Treatment Plans** | ● Full lifecycle; ◐ ~10 duplicate column pairs | Deduplicate. Add: **four response strategies with `sharing` modelled properly** (counterparty, shared proportion, residual retained) — today `strategy` is a free string and sharing is absent · milestones as a table not a text field · dependency graph · **expected vs actual residual movement charted** (the "Planned residual" third value) · on-track/off-track dashboard matching the Corporater pattern · cost-benefit and control ROI |
| **KRI/KPI** | ● Definitions, RAG bands, measurements, breach detection; ○ no breach register, no automation | Unify into the **measure engine**. Add: `measure_breaches` register with acknowledgement and MTTR · automated collection via connectors (finally honouring `automation_config`) · seasonality-aware and multivariate anomaly detection · leading-indicator discovery (which KRIs actually predicted past losses) · **KPI alongside KRI** for strategy linkage |
| **Loss Events** | ● **Best-in-class**, Basel + CBN ORMS, recoveries, RCA, near-miss | Consolidate the duplicate near-miss representation. Add: **boundary event handling** (credit/market), timing losses, rapidly recovered losses, grouped/related loss linkage · GL reconciliation · **external loss data benchmarking** (ORX/ORIC-style with scaling) · **USSD/WhatsApp intake** · AI similarity detection and duplicate merge |
| **Issues/Actions** | ● Config-driven escalation, closure workflow | Add: a single action register aggregating audit, risk, control test, incident, regulator and external audit sources · extension approval workflow · validation testing on closure |
| **Quantification** | ● Poisson–Lognormal MC, VaR ×4, ICAAP; ○ no copula, stress config unused, synchronous | **The strategic build.** Async job engine with progress. Distribution library (PERT/BetaPERT, triangular, normal, uniform, Poisson, negative binomial, Weibull, Pareto, GPD, empirical). Distribution fitting with goodness-of-fit. **FAIR decomposition** (TEF, Vulnerability, PL, SLEF, SLM, six loss forms). **Loss exceedance curves as the primary board visual.** CVaR/Expected Shortfall. **Portfolio aggregation with rank correlation and Gaussian/t copulas** (honouring the column that already claims it). Sensitivity/tornado. **Control ROI** ("this ₦400m control reduces 95th-percentile annual loss by ₦3.1bn"). Stress and **reverse stress testing** (finally applying `stress_multiplier_*`). LDA with EVT/POT tails. Internal + external loss data + scenarios + BEICFs. **Backtesting against realised losses.** Basel SMA (BI/BIC/ILM/ORC). Financial-KPI projection (**EBITDA / FCF / NPV tornado bars**, matching Corporater's Financial Risk tab). |
| **Analysis** | ◐ Heatmap (hardcoded 5×5), read-only bow-tie, trends, correlation | **Assessment perspectives as scoped contexts** — asset, process, external, operational scenario, legal and **treasury/ALM** — each with its own register, ID series and dashboard tab set · Configurable-dimension heat map with **cell counts and drill-through** · **opportunity heat map** · **editable bow-tie with barriers, escalation factors and barrier effectiveness** · **FMEA/FMECA with S×O×D, RPN and AIAG-VDA Action Priority** · **HAZOP** with node/parameter/guide-word structure · fault tree and event tree · Pareto with drill-down · control-effectiveness bubble chart |
| **Reporting** | ◐ On-screen views only; CSV the sole file output; no PDF; fixed dashboard | **Reporting-governance objects** (who owes which report, at what periodicity, above what threshold, in what format, with delivery evidence) plus **Report Studio**: template designer, **PDF/XLSX/PPTX/DOCX output**, scheduled distribution, **board pack assembly** with cover, contents, narrative sections and appendices, version control and approval, regulatory pack generation (ICAAP, ORSA, CSAT, CAR, self-assessment), and period-locked reproducibility |
| **Dashboards** | ○ One 48.6 KB fixed layout | **Widget engine + Business HQ + My Responsibilities** (§6.6) |
| **Regulatory Compliance** | ◐ Circulars, deadlines, filings — no obligation chain | Full **obligation register at clause level** → policy → control → risk → process → evidence chain. Multi-framework cross-mapping. Framework version migration (e.g. 27001:2013 → 2022). Regulatory change feed with **applicability filtering by entity/jurisdiction/product**. **Obligation-level AI diffing.** Examination management (requests, responses, findings, commitments). Regulatory pack generation. |
| **Policy Management** | ○ Absent | Full module: repository with version control and a single published version · authoring → review → approval → publication → archival · policy → standard → procedure → work instruction hierarchy · **attestation campaigns** with escalation · targeted distribution by role/entity/geography · exception/waiver workflow with expiry and compensating control · employee policy portal (mobile) |
| **Third-Party Risk** | ○ Absent | Vendor master with lifecycle · criticality tiering · tier-gated onboarding due diligence · questionnaire library with vendor portal · evidence with certification expiry and auto-chasing · contract repository with clause/obligation extraction · **sanctions/PEP/adverse media screening** · concentration risk · structured offboarding · **nth-party mapping** · **Nigerian agent-network modelling** (per-agent exclusivity, transaction limits, principal liability) |
| **Operational Resilience / BCM** | ○ Absent | **Important Business Service** register with board-approved definitions · **impact tolerances** · dependency mapping (people, premises, technology, information, third parties) · **BIA** with MTPD/MAO, RTO, RPO, MBCO · BC and DR plans with version control · crisis management structure and call trees · exercise programme · **severe-but-plausible scenario testing against tolerance with pass/fail** · self-assessment document with board approval · ISO 22301 clause evidence |
| **Internal Audit** | ○ Absent | **Risk-function resource adequacy evidence** (people and technology — what regulators ask at examination) · Audit universe with risk-based scoring · risk-based annual plan with capacity planning · engagement management · **RACM** per engagement · electronic workpapers with review notes · findings with management response · **IIA Global Internal Audit Standards (2024)** conformance · QAIP · audit committee pack · **combined assurance map across three lines** |
| **ESG & Climate** | ○ Absent | ESG metric library with unit conversion · data collection with source evidence · **double materiality assessment** · **IFRS S1/S2** with Nigerian FRC roadmap staging · TCFD · **climate as a native risk category** with physical/transition taxonomy, scenario analysis (quantitative and qualitative), Scope 1/2/3 with the year-one Scope 3 relief flag, targets with performance tracking · **audit-grade lineage for ISSA 5000 reasonable assurance** |
| **Strategy & Performance** | ○ Absent | Objectives hierarchy · KPI linkage (same measure engine) · **Project and Portfolio as first-class risk-bearing nodes** with treatment and control aggregation · **KRI-to-objective linkage** ("this KRI is breaching, and here is the objective it threatens") · **risk-to-objective mapping** ("which strategic objectives are at risk" — Corporater's stated differentiator, the "P" in GPRC) · initiative and project portfolio · balanced scorecard view |
| **AI Governance** | ○ Absent | AI system/agent inventory · **EU AI Act** use-case risk classification · **ISO 42001** conformance · bias/fairness/drift monitoring · model cards · human-oversight attestation · **our own agents registered as governed objects** — "we govern our own agents with the machinery we sell you" |
| **AI Intelligence** | ✖ **Fabricated (`mt_rand`)** | **Delete, then rebuild on real data.** Predictive: actual model on the org's own history with honest, computed accuracy metrics. Radar: real horizon scanning from ingested sources. Benchmarking: real anonymised cross-tenant aggregates with a documented k-anonymity floor, or removed. Every AI output must carry provenance, confidence and a human review path. |
| **AI Tools (LLM)** | ● Real Ollama integration, only partially UI-wired | Wire the existing endpoints. Add: document → risk extraction with de-duplication against the graph · **AI-assisted RCSA** · control-to-framework auto-mapping with confidence and review queue · NL query over the graph **with permission enforcement and citation trail** · agentic workflows with declared scope, approval thresholds, action log and kill switch |

---

# 8. Roadmap

## 8.1 Work packages

| WP | Title | Weeks | Depends on | Outcome |
|---|---|---|---|---|
| **WP-00** | Security & tenancy kernel | 3 | — | Global scopes, node-scoped authz, RBAC on all routes, MFA enforced, SSO foundation, backdoor removed, assets self-hosted |
| **WP-01** | Data integrity & schema debt | 3 | — | Duplicate columns consolidated, morph map, org-scoped reference codes, race fixed, appetite service repaired, audit trail repaired, unit tests on all four calculation services |
| **WP-02** | Honest AI + demo blockers | 2 | — | `mt_rand` removed, RCSA stub fixed, PDF/XLSX output, board pack v1 |
| **WP-03** | Object Graph Core | 5 | WP-00, WP-01 | `object_types`, `objects`, `object_relationships`, lifecycles, versions; org models unified; `HasObjectIdentity` on all models |
| **WP-04** | Period & Measure Engine | 4 | WP-03 | `periods`, `measures`, `measure_values`, `measure_thresholds`, `measure_breaches`, `fx_rates`; KRIs migrated; risk scores period-stamped |
| **WP-05** | Metadata & Config Engine | 4 | WP-03 | Object type/attribute builder, dynamic form renderer, config bundles with diff and promotion |
| **WP-06** | Workflow Engine v2 | 4 | WP-03 | Parallel, gateways, timers, SLA, escalation, delegation; all three legacy mechanisms migrated |
| **WP-07** | API, jobs & integration foundation | 4 | WP-03 | `routes/api.php`, Sanctum/OAuth2, OpenAPI 3.1, webhooks, Redis + Horizon, `app/Jobs/`, connector framework, MCP server |
| **WP-08** | Widget & Dashboard Engine + Business HQ | 5 | WP-03, WP-04 | Widget definitions, context binding, dashboard builder, per-node Business HQ, **My Responsibilities** |
| **WP-09** | Front-end system & design system | 4 | WP-08 | Livewire 3, Vite build, `<x-data-grid>`, chart library on the dataviz standard, i18n scaffolding, WCAG 2.2 AA |
| **WP-10** | Risk domain uplift | 4 | WP-04, WP-05 | Configurable scales, cause→event→consequence, opportunity model, real target scores, appetite rewrite with cascade and inflation-indexed thresholds, acceptance with expiry |
| **WP-11** | Assessment & analysis uplift | 4 | WP-06, WP-10 | RCSA lifecycle with challenge and pre-population, questionnaire builder UI, editable bow-tie, FMEA, HAZOP, configurable heat map with drill-through, opportunity heat map |
| **WP-12** | Reporting & Report Studio | 4 | WP-08 | Template designer, PDF/XLSX/PPTX/DOCX, scheduled distribution, board pack assembly, regulatory packs |
| **WP-13** | Offline-first PWA & mobile | 5 | WP-09 | Service worker, IndexedDB, outbox with conflict resolution, resumable uploads, autosave, 2G performance budget, mobile first-line journeys |
| **WP-14** | USSD & WhatsApp intake | 3 | WP-07 | Short-code and WhatsApp Business API channels with structured parsing into incident/KRI records |
| **WP-15** | Currency, FX & inflation indexing | 2 | WP-04 | Multi-currency with rate types (CBN official / NAFEM / parallel), reporting-currency conversion, formula thresholds, re-baselining workflow |
| **WP-16** | Content pack framework + NG-CORE, NG-BANK | 4 | WP-05 | Pack install/upgrade with binding preservation; the two flagship Nigerian packs |
| **WP-17** | Content packs — sectors & standards | 4 | WP-16 | NG-INSURANCE, NG-PENSION, NG-CAPMKT, NG-DATA, NG-PUBLIC, NG-ENERGY, NG-TELCO + ISO/COSO/Basel/NIST/IFRS-S standard packs |
| **WP-18** | Quantification L5 — FAIR, LEC, distributions | 5 | WP-07 | Distribution library, fitting, FAIR decomposition, loss exceedance curves, CVaR, tornado, async engine |
| **WP-19** | Quantification L6 — portfolio, capital, backtesting | 5 | WP-18 | Copula correlation, LDA with EVT/POT, reverse stress testing, control ROI optimisation, backtesting, Basel SMA, financial-KPI projection |
| **WP-20** | Compliance & obligations | 4 | WP-16 | Clause-level obligation register, full linkage chain, multi-framework mapping, version migration, examination management |
| **WP-21** | Policy management | 3 | WP-06 | Full module incl. attestation campaigns and employee portal |
| **WP-22** | Third-party risk | 4 | WP-06, WP-20 | Vendor lifecycle, tiering, portal, contracts, screening, concentration, nth-party, agent networks |
| **WP-23** | Operational resilience & BCM | 4 | WP-03 | IBS, impact tolerance, dependency mapping, BIA, plans, exercises, scenario testing |
| **WP-24** | Internal audit & combined assurance | 4 | WP-06 | Universe, plan, engagements, RACM, workpapers, findings, IIA 2024 conformance, assurance map |
| **WP-25** | Continuous control monitoring | 4 | WP-07 | Scripted tests, full-population testing, auto-exception, control health time series |
| **WP-26** | ESG, climate & IFRS S1/S2 | 4 | WP-04, WP-16 | Metric library, double materiality, climate risk taxonomy and scenarios, Scope 1/2/3, FRC roadmap staging |
| **WP-27** | Strategy & performance | 3 | WP-04 | Objectives, KPI linkage, risk-to-objective mapping, initiative portfolio |
| **WP-28** | AI governance module | 3 | WP-03 | AI inventory, EU AI Act classification, ISO 42001, drift monitoring, our own agents governed |
| **WP-29** | Grounded AI — extraction, RCSA assist, NL query, agents | 6 | WP-03, WP-07 | Document→risk extraction with de-dup, AI-assisted RCSA, control auto-mapping with review queue, NL query with permissions and citations, governed agents |
| **WP-30** | Deployment tiers & residency | 3 | WP-00 | Nigeria-region cloud, partner private cloud, hardened on-prem installer, residency attestation, DR/RTO documentation |

## 8.2 Phasing

### Phase 0 — Stabilise (weeks 1–4) · WP-00, WP-01, WP-02
**Nothing else starts until this lands.** Tenant isolation, RBAC enforcement, schema debt, fabricated AI removed, RCSA stub fixed, PDF output, unit tests on the four calculation services.
**Exit:** no route without an authorization guard · no `?? 1` in the codebase · one source of truth per field · `mt_rand` absent from `app/` · a board report that is a PDF · CI green with >70% coverage on scoring, control effectiveness, Monte Carlo and regulatory thresholds.

### Phase 1 — Platform core (weeks 5–16) · WP-03 → WP-09
The architectural bet. Object graph, period model, config engine, workflow v2, API, widgets, front-end system.
**Exit:** every domain model has an `objects` row · a risk score exists per period · a widget rendered on two different nodes returns two correct different results · one workflow definition drives approvals in four modules · `/api/v1` covers the object model with a published OpenAPI spec · Business HQ and My Responsibilities are live.

### Phase 2 — Corporater parity (weeks 13–24, overlapping) · WP-10 → WP-12, WP-16
Risk domain uplift, assessment and analysis, Report Studio, the first two content packs.
**Exit:** all 64 Corporater parity items (54 published features + 10 benefits-prose claims) present or consciously deferred with a reason · the ERM object page reproduces the eight-tab structure · opportunity and consequence heat maps both render · inherent/residual/planned charts render · Pareto with drill-down · control-effectiveness bubble · a board pack generates as PDF from a period query.

### Phase 3 — the Africa moat (weeks 21–32, overlapping) · WP-13 → WP-17, WP-30
Offline PWA, USSD/WhatsApp, currency and inflation indexing, sector content packs, deployment tiers.
**Exit:** a full RCSA completed offline on a mid-range Android at simulated 2G and synced without loss · a loss event filed by USSD and by WhatsApp lands as a structured record · an appetite threshold defined as `0.5% of qualifying capital` re-baselines at period close with approval · a Nigeria-resident deployment is documented, tested and priced.

### Phase 4 — beyond parity (weeks 25–44, overlapping) · WP-18 → WP-28
Quantification levels 5 and 6, compliance, policy, TPRM, resilience, audit, CCM, ESG/climate, strategy, AI governance.
**Exit:** a loss exceedance curve is the default board visual · portfolio aggregation uses a copula, not a sum · control ROI is computable · a DORA-grade vendor register exists · an IBS with impact tolerance passes a scenario test · IFRS S1/S2 staging is tracked against the FRC roadmap.

### Phase 5 — grounded AI (weeks 33–44+) · WP-29
Only after the graph exists. AI grounded on a typed graph beats AI over a document store, which is the entire reason this comes last.

## 8.3 The 13-week demo cut — if you need to sell before the platform lands

If a deal cycle forces an earlier demo, this subset produces a product that beats anything currently sold into the Nigerian mid-market:

**Weeks 1–4:** WP-00, WP-01, WP-02 (mandatory — never demo the fabricated AI)
**Weeks 5–9:** WP-03 (object graph) + WP-04 (period model), scoped to risk, control, KRI and org nodes only
**Weeks 8–12:** WP-08 (widgets + Business HQ + My Responsibilities) + WP-12 (Report Studio, PDF board pack)
**Weeks 10–13:** WP-16 (NG-CORE + NG-BANK content pack) + WP-15 (currency and inflation indexing)

**What that demo shows:** a period selector that changes the whole screen · a Business HQ page for any node in the bank's own structure · a My Responsibilities inbox · configurable widget dashboards with real heat maps, Pareto and trend charts · a PDF board pack generated from a period query · CBN obligations, calendar and Nigerian taxonomy pre-loaded on day one · appetite thresholds expressed as a percentage of qualifying capital · loss events with CBN/NFIU clocks running.

That is a winning demo. Everything after it widens the moat.

---

# 9. Success metrics

## 9.1 Product

| Metric | Baseline | Target |
|---|---|---|
| Weighted parity with Corporater (64 items incl. benefits-prose claims) | 38.2% | **95%+** by end of Phase 2 |
| Weighted coverage of the 350-item world-class scorecard | 13.8% | **70%+** by end of Phase 4 |
| Table-stakes coverage | ~19% | **95%** by end of Phase 2 |
| Leading-edge coverage | 0% | **35%+** by end of Phase 4 |
| Quantification maturity level | 4 | **6** |
| Routes with an authorization guard | 0 / 203 | **203 / 203** by week 3 |
| `mt_rand` occurrences in `app/` | 12+ | **0** by week 4 |
| Unit test coverage on calculation services | ~0% | **>85%** |
| Time-to-first-board-report for a new customer | unknown | **< 5 working days** with a content pack |
| First meaningful paint on simulated 2G | untested | **< 3 s** |
| Modules using one workflow engine | 0 of 3 mechanisms | **1 engine, all modules** |

## 9.2 Commercial

| Metric | Target |
|---|---|
| Reference customers by sector | 2 banks, 2 insurers, 1 PFA, 1 MDA, 1 listed corporate within 12 months of Phase 3 |
| Pricing | Naira, locally invoiced, **flat or entity-tiered — never per-seat**; mid-market band ₦4m–₦30m/yr equivalent |
| Deployment options live | Nigeria-region cloud + Nigerian-partner private cloud + on-premise, all three priced |
| Content pack refresh cadence | Regulatory content updated within **10 working days** of a new CBN/SEC/NAICOM/PenCom/NDPC instrument |
| Channel | 2+ Big Four or local risk advisory implementation partners |

## 9.3 Adoption — the metric that actually predicts renewal

| Metric | Target |
|---|---|
| Monthly active **first-line** users / total licensed users | **> 60%** |
| RCSA campaign completion rate without manual chasing | **> 85%** |
| Median time to complete a My Responsibilities task | **< 3 minutes** |
| Loss events captured via mobile/USSD/WhatsApp | **> 40%** of all events at agent-network customers |
| Risk register entries updated in the current period | **> 90%** |

---

# 10. Risks to this plan

| Risk | Impact | Mitigation |
|---|---|---|
| **The object-graph migration destabilises working modules** | High | Additive-only. `objects` is a graph *index* alongside existing typed tables, not a replacement. Feature-flag every cutover. Keep old pivots as views. Never drop a column in the same release that stops writing to it. |
| **Scope sprawl — 30 work packages, 44 weeks** | High | Phase 0 and Phase 1 are non-negotiable and sequential. Everything in Phase 4 is independently deferrable. The 13-week demo cut is the fallback commitment. |
| **The team is small relative to the ambition** | High | Content packs are data, not code — a domain SME can build NG-BANK without an engineer once WP-16 lands. Prioritise WP-05 (config engine) precisely so non-engineers can extend the product. |
| **Regulatory content goes stale — and stale content is worse than none** | High | Content packs are versioned and updatable with a published refresh SLA. Assign a named owner. Build a regulatory-change watch process, not a one-time seed. |
| **Fabricated AI reaches a customer before it is removed** | Severe | WP-02 in week 3–4. Until then, the AI Intelligence routes should be disabled in any environment a customer can reach. |
| **Data residency deadline (1 Jan 2027) arrives before a hosted tier exists** | High | WP-30 has no hard dependency beyond WP-00 — start the hosting partner conversation in month 1, not month 8. |
| **Offline-first is harder than estimated** | Medium | Scope it to five first-line journeys, not the whole app. Read-mostly offline for registers; write-offline only for the five journeys. |
| **Quantification depth exceeds what customers can supply data for** | Medium | Ship the maturity ladder as a guided path: level 3 semi-quantitative works with no history; levels 5–6 unlock as loss data accumulates. Pair with calibration training in-product. |
| **Global vendor drops naira pricing and a Lagos region** | Medium | Their per-seat model and their architecture are the constraint, not their willingness. Offline-first, USSD/WhatsApp and inflation-indexed thresholds are architectural commitments they cannot retrofit quickly. Keep widening on those axes. |
| **Losing the existing Nigerian regulatory depth during refactoring** | High | `RegulatoryThresholdService`, the loss-event schema and the ICAAP model are the crown jewels. Write characterisation tests around them **before** WP-01 touches anything. |

---

# 11. Immediate next steps

1. **Approve the architecture decision** — metadata-driven object graph + period model, evolving the Laravel codebase in place.
2. **Freeze customer-facing demos of the AI Intelligence module today.** Disable those four routes in any reachable environment.
3. **Start WP-00, WP-01 and WP-02 in parallel** — they are independent and all three are blockers.
4. **Open the hosting-partner conversation this month** for the Nigeria-resident tier (WP-30). The 1 January 2027 deadline is fixed.
5. **Appoint a content owner** for NG-BANK. Regulatory content is the moat and it needs a named human, not a sprint.
6. **Run the execution prompt pack** (`01_EXECUTION_PROMPT_PACK.md`) — each work package has a self-contained, ready-to-run prompt with acceptance criteria.

---

*Companion documents: `01_EXECUTION_PROMPT_PACK.md` (ready-to-run implementation prompts) · `ERM_Gap_Analysis_Scorecard.xlsx` (the full 350-item scorecard).*
