# ERM Remodel — Execution Prompt Pack

Ready-to-run implementation prompts, one per work package. Each is self-contained: paste it into a Claude Code / coding-agent session opened at the project root and it has everything it needs.

**Project root:** `/Users/mac/Documents/devs/laravel/grcsuite/risk copy`
**Companion:** `00_ERM_REMODEL_MASTER_PLAN.md`

---

## How to use this pack

1. **Run in order.** Dependencies are stated per WP. WP-00, WP-01 and WP-02 are independent of each other and can run in parallel; nothing else starts until all three are merged.
2. **One WP per branch**, one PR per WP. Never mix a schema change with a behaviour change in the same commit.
3. **Paste the Standing Context block below at the top of every session**, then the WP prompt.
4. **Every WP must leave CI green.** If a WP cannot leave CI green, it is too big — split it.

---

## Standing context — prepend to every prompt

```
You are working on a Laravel 12 / PHP 8.2 Enterprise Risk Management platform at
/Users/mac/Documents/devs/laravel/grcsuite/risk copy

STACK: Laravel 12, PHP 8.2, MySQL (config default is sqlite — tests use sqlite),
Blade views (142), spatie/laravel-permission v7 for RBAC, Vite + Tailwind 4
configured but currently unused (assets load from CDN — being fixed in WP-00).
60 migrations, ~50 domain tables, 30 controllers, 203 named routes, no routes/api.php.

PARITY BASELINE: 64 Corporater items — the 54 published solution features plus
10 capabilities their Solution Benefits prose claims that the feature list omits
(three-lines assurance map, risk maturity correlation, project/portfolio as
risk-bearing nodes, four-strategy response including SHARING, graph-derived
roll-up, operational-to-strategic single register, KRI-to-objective linkage,
reporting governance, resource adequacy evidence, single-platform GRC
extension). See section 2.4 of the master plan.

PRODUCT DIRECTION: We are remodelling this into a metadata-driven, object-graph
ERM platform mirroring Corporater's architecture (digital twin of the organisation,
period-aware measure model, context-bound dashboard widgets, configure-don't-code),
tailored for Nigerian and African organisations (CBN/SEC/NAICOM/PenCom/NDPC/FRC
content, offline-first, USSD/WhatsApp capture, naira-native multi-currency,
inflation-indexed thresholds).

NON-NEGOTIABLE ENGINEERING RULES
1. ADDITIVE MIGRATIONS ONLY. Never drop a column in the same release that stops
   writing to it. Deprecate across two releases: (a) stop writing + backfill,
   (b) drop.
2. Every tenant-scoped model MUST use the BelongsToOrganization trait and its
   global scope. NEVER write `auth()->user()->organization_id ?? 1` — a null
   organization_id is a hard 403.
3. Every route MUST have an authorization guard. CI fails if any route lacks one.
4. Never introduce mt_rand(), rand(), or hardcoded "model metrics" into anything
   a user sees. If a number is not computed from real data, it does not ship.
   EXCEPTION: MonteCarloService legitimately needs an RNG for Poisson and
   Box-Muller variates (lines 22, 34, 35). Simulation engines are allowlisted —
   and must use a SEEDABLE generator so results are reproducible in tests.
5. Money is stored in minor units (kobo) as bigInteger, ALWAYS with a currency
   code and, where converted, the FX rate used.
6. New code is Livewire 3 + Alpine over Blade. No SPA rewrite, no new front-end
   framework.
7. Write tests first for anything that produces a number. The four calculation
   services (RiskScoringService, ControlEffectivenessService, MonteCarloService,
   RegulatoryThresholdService) require >85% unit coverage.
8. Run `./vendor/bin/pint` before committing. Keep CI green.

CROWN JEWELS — do not regress these. Write characterisation tests before touching:
  app/Services/RegulatoryThresholdService.php  (CBN/NFIU/BOFIA/NDIC/EFCC thresholds,
                                                deadlines and statutory citations)
  app/Models/LossEvent.php + migrations 200017–200023 (Basel L1/L2/L3 + CBN ORMS,
                                                five-way recovery decomposition)
  app/Services/MonteCarloService.php + 200030–200034 (quantification + ICAAP)
  app/Services/ControlEffectivenessService.php (weighted control aggregation)

Before you change anything: read the relevant files, state your plan, then implement.
Report what you changed and what you deliberately did not.
```

---

# PHASE 0 — STABILISE (weeks 1–4)

---

## WP-00 — Security & Tenancy Kernel

**Depends on:** nothing · **Estimate:** 3 weeks · **Blocks:** everything

```
GOAL
Make the platform safe to sell. Today: zero tenant isolation enforcement, zero
route authorization, MFA unenforced, a dev auto-login backdoor in the repo, and
every page load fetching assets from three foreign CDNs — which destroys the
on-premise data-residency claim.

TASK 1 — TENANT ISOLATION
- Create app/Support/Tenancy/TenantContext.php: a container-bound singleton
  holding the resolved organization_id for the request/job.
- Create app/Models/Concerns/BelongsToOrganization.php: a trait adding
  (a) a global scope filtering on organization_id from TenantContext,
  (b) a creating() hook stamping organization_id,
  (c) a bypassTenancy() escape hatch for system jobs, logged when used.
- Create app/Http/Middleware/ResolveTenant.php: binds TenantContext from
  auth()->user()->organization_id. If null → abort(403, 'No organization
  assigned'). Register it in bootstrap/app.php in the web and api groups.
- Apply BelongsToOrganization to EVERY model with an organization_id column
  (grep the migrations for organization_id to get the exhaustive list).
- Remove EVERY occurrence of `?? 1`. There are 166 across 22 files (worst:
  LossEventController 22, QuantificationController 20, IssueController 19).
  `grep -rn "organization_id ?? 1" app/` must return zero. Replace with
  TenantContext::organizationId().
- FIX TWO SPECIFIC CROSS-TENANT READS:
  * MonteCarloService::runSimulation() line 49 —
    QuantificationScenario::whereIn('id', $scenarioIds) has NO organization
    filter. Any tenant can simulate another tenant's scenarios by id.
  * ReferenceCodeService::generate() lines 17-20 — no organization_id filter,
    so reference codes already draw from a global cross-tenant pool.
- Add tests/Feature/TenancyIsolationTest.php: for each tenant-scoped model,
  seed two organizations, authenticate as org A, assert org B rows are
  invisible to index, show, update and delete.

TASK 2 — ROUTE AUTHORIZATION
- Read routes/web.php and database/seeders/RolesAndPermissionsSeeder.php
  (46 permissions at lines 24-92; 9 roles).
- Produce a route→permission matrix. Where an existing permission fits, use it;
  where none fits, add one to the seeder following the existing naming
  convention.
- Apply `permission:` middleware to every route. Alias CheckPermission properly
  in bootstrap/app.php (it is aliased but never used).
- Add tests/Feature/RouteAuthorizationTest.php that enumerates Route::getRoutes()
  and FAILS if any route in the web or api group lacks either a `permission:`
  or `can:` middleware (allowlist: login, logout, password reset, health).

TASK 3 — NODE-SCOPED AUTHORIZATION FOUNDATION
- Create app/Support/Authorization/GraphScope.php with a scopeVisibleTo(User)
  macro that filters by hierarchy_path prefix when the user's role grants
  subtree-limited access. Wire it into Risk, Control, Issue, LossEvent,
  KeyRiskIndicator. Full-org roles bypass it. Add tests.

TASK 4 — MFA + SSO FOUNDATION
- Alias EnsureMfaVerified in bootstrap/app.php and apply it to the auth
  middleware group. Add an organization setting `mfa_required_roles` (json)
  and enforce MFA for those roles at login.
- Install laravel/socialite. Add config/sso.php supporting OIDC (Microsoft
  Entra ID, Google Workspace, Okta) and SAML 2.0. Implement:
  * GET  /auth/sso/{provider}/redirect
  * GET  /auth/sso/{provider}/callback  → find-or-provision user, map IdP
    groups to Spatie roles via a configurable map, enforce is_active.
- Add a SCIM 2.0 /scim/v2/Users + /scim/v2/Groups endpoint behind a bearer
  token for user provisioning (create, update, deactivate).

TASK 5 — REMOVE THE BACKDOOR AND HARDEN
- Delete app/Http/Middleware/AutoLoginDev.php entirely. NOTE: it is registered
  NOWHERE (not in bootstrap/app.php, not on any group), so it is dormant dead
  code, not a live bypass. Delete it anyway — but the real exposure is TASK 2,
  not this file.
- Add a boot-time assertion in AppServiceProvider: if app()->environment() is
  not 'local' and config('app.debug') is true, throw.
- Add .env.example entries for all new config. Add a deploy preflight artisan
  command `app:preflight` checking APP_DEBUG=false, APP_ENV, HTTPS enforcement,
  session secure cookies, and that no CDN host appears in compiled views.
- Rotate the SMTP credentials currently committed in .env; document the rotation.
- install.php (16.9 KB) sits at the PROJECT ROOT, outside public/. Under a
  standard Laravel docroot it is already unreachable — confirm no deployment
  serves the project root, then delete it.

TASK 6 — SELF-HOST ALL FRONT-END ASSETS
- resources/views/layouts/app.blade.php currently loads Tailwind, Alpine,
  Chart.js and Google Fonts from CDNs. Move ALL of them into the Vite build:
  npm install tailwindcss @tailwindcss/vite alpinejs chart.js
  Self-host the Inter font and Material Symbols under public/fonts.
  Replace the CDN <script>/<link> tags with @vite(['resources/css/app.css',
  'resources/js/app.js']).
- Move the inline tailwind.config theme (primary #1A365D, secondary #2D7D46,
  accent #D4AF37) into a real tailwind.config.js, and make brand colours
  overridable per organization from organizations.settings->branding.
- Add a test asserting no compiled Blade view references cdn.tailwindcss.com,
  cdn.jsdelivr.net or fonts.googleapis.com.

TASK 7 — AUDIT LOG HARDENING
- Make risk_audit_trail append-only: add a `hash` column containing
  sha256(previous_hash || row payload), populated on insert, and a
  `audit:verify` artisan command that walks the chain and reports breaks.
- Add a database-level guard (trigger or a model observer that throws) against
  update/delete on the table.

ACCEPTANCE
□ grep -rn "organization_id ?? 1" app/ returns nothing
□ TenancyIsolationTest passes for every tenant-scoped model
□ RouteAuthorizationTest passes — 203/203 routes guarded
□ AutoLoginDev.php does not exist
□ No compiled view references an external CDN
□ SSO login works against a test Entra ID tenant
□ MFA is enforced for at least one role
□ audit:verify reports an unbroken chain on seeded data
□ ./vendor/bin/pint --test passes, full suite green
```

---

## WP-01 — Data Integrity & Schema Debt

**Depends on:** nothing (run parallel with WP-00) · **Estimate:** 3 weeks

```
GOAL
There is currently more than one source of truth for many fields, several
services read columns that do not exist, and the audit trail silently returns
nothing. Fix the foundation before building on it.

TASK 0 — CHARACTERISATION TESTS FIRST
Before changing anything, write tests that pin CURRENT correct behaviour for:
  app/Services/RegulatoryThresholdService.php — every threshold, deadline and
    citation (CBN BSD/DIR/GEN/LAB/07/014 7-day, NFIU 24-hour, NDIC Act 2023 §41,
    AML/CFT Act 2022 §6(1), EFCC Act 2004, CBN Cyber Security Framework 2021)
  app/Services/MonteCarloService.php — seed the RNG, assert VaR at 90/95/99/99.9
    and the percentile curve for a known scenario
  app/Services/ControlEffectivenessService.php — weighted aggregation across
    controls with differing control_weight
  app/Models/LossEvent.php — netLossAmountKobo() across all five recovery types
These are the crown jewels. They must not regress.

TASK 1 — CONSOLIDATE DUPLICATE COLUMNS
Migration 2026_02_22_200038_align_schema_with_controllers.php duplicated whole
field sets instead of renaming. Affected: treatment_plans (19 dup columns),
loss_events (25), issues (25), loss_event_rca (10).

For each duplicated pair, do this in TWO migrations:
  Migration A — backfill + single-writer:
    * Choose the CANONICAL column (prefer the original 200017/200010/200024
      naming; document the choice in the migration docblock).
    * Backfill canonical from the duplicate where canonical is null.
    * Update all models, controllers, services, exports, reports and imports to
      read and write ONLY the canonical column.
    * Keep the coalescing accessors on the models temporarily, now reading only
      the canonical column.
  Migration B (next release) — drop the duplicate columns and the accessors.

Ship Migration A in this WP. Create a follow-up ticket for Migration B.
Produce docs/schema/canonical-columns.md listing every pair and the decision.

TASK 2 — FIX THE BROKEN SERVICES
- app/Services/RiskAppetiteService::compareAgainstActual() (lines 37, 42, 46,
  53-57) reads tolerance_upper, capacity, appetite_type, tolerance_lower and
  notes — none of which exist on risk_appetite. The real columns are
  appetite_level, appetite_statement, max_tolerance, target_min, target_max.
  PRECISE IMPACT: lines 37 and 46 read max_tolerance/target_max FIRST, so the
  tolerance bounds do resolve correctly. But `capacity` is always 25,
  `appetite_type` is always 'Not Specified' and `notes` is always '' — even
  though appetite_level and appetite_statement hold the real answers.
  Either add the missing columns via migration (preferred — capacity and
  appetite_type are genuinely needed for the WP-10 appetite rewrite) or rewrite
  the service against the real columns. Add tests either way.
- app/Services/AuditTrailService writes class_basename($entity) ("Risk") while
  Risk::auditTrail() queries where('entity_type','risk'). Introduce a proper
  Laravel morph map in AppServiceProvider (Relation::enforceMorphMap([...]))
  and use it consistently in risk_audit_trail, approval_requests,
  workflow_instances and notifications_log. Backfill existing rows.
  Add a test asserting $risk->auditTrail returns rows after an update.
- Two conflicting rating bands: RiskScoringService::calculateRating() uses >=5
  for Medium; Risk::inherentRating() uses >=6. Delete the accessor's duplicate
  logic and delegate to the service. One implementation.
- Two conflicting effectiveness maps: Control::EFFECTIVENESS_PERCENT_MAP
  (100/50/0) vs ControlEffectivenessService::$effectivenessMap
  (95/80/60/37/12). Keep the five-band service map, make it configurable per
  organization, delete the constant, update all callers.
- RiskScoringService::calculateMaxImpact() omits impact_strategic which
  RiskAssessment::impactScore() includes. Include it, and make the aggregation
  method configurable (max | weighted | average | worst_two).
- DataImportController::createRecordForType('issues') writes issue_code; the
  column is issue_reference. Fix and add a test.

TASK 3 — ORG-SCOPED REFERENCE CODES + RACE FIX
These are globally unique but must be per-organization:
  control_tests.test_code, loss_events.event_reference, near_misses.reference,
  issues.issue_reference, quantification_scenarios.scenario_reference,
  simulation_runs.simulation_reference, assessment_campaigns.campaign_code
- Migration: drop the global unique index, add UNIQUE(organization_id, <code>).
- Rewrite app/Services/ReferenceCodeService::generate() — it currently does an
  unguarded read-then-write with orderByDesc on a string column (race condition,
  and lexicographic ordering breaks past 9999). Replace with a dedicated
  reference_sequences table (organization_id, sequence_key, prefix, next_value,
  padding) incremented inside a transaction with SELECT ... FOR UPDATE.
- Add a concurrency test: 50 parallel generate() calls produce 50 distinct codes.
- NOTE: ReferenceCodeService currently has NO organization_id filter at all, so
  existing codes were drawn from a global pool. Before applying
  UNIQUE(organization_id, code), run a collision audit across existing rows and
  produce a remediation report — do not let the migration fail in production.

TASK 4 — TYPE AND DRIVER FIXES
- loss_event_attachments.file_size_bytes, issue_attachments.file_size_bytes and
  control_test_evidence.file_size are integer (2 GB ceiling) → bigInteger.
- 2026_04_23_110000_add_rejected_status_to_control_tests.php is MySQL-only
  (guarded on DB::getDriverName()). Make the enum consistent across drivers, or
  convert the column to a string with an application-level enum cast (preferred).
- Risk::appetiteStatement() uses hasOneThrough(RiskAppetite, RiskCategory) and
  will pick an arbitrary row when a category has several appetites. Replace with
  an explicit effective-dated lookup (latest effective_date <= now, not expired).

TASK 5 — RETIRE ORPHANS (mark, do not delete yet)
- risk_kri_mapping is documented as unused in Risk.php (line 197).
  loss_events.is_near_miss duplicates the near_misses table.
  Add a docblock @deprecated to each, log a warning on use, and create
  docs/schema/deprecations.md with the removal release.
- DO NOT deprecate risk_taxonomies. It has no foreign key from any table, but it
  IS a live feature with its own CRUD surface (RegulatoryComplianceController
  lines 202, 220, 224 and views/risk/regulatory/taxonomy.blade.php line 22).
  Deprecating it would fire warnings on working functionality. It is a
  disconnected parallel tree to be MERGED into the unified taxonomy service in
  WP-10, not retired.

TASK 6 — UNIT TEST THE CALCULATION SERVICES TO >85%
RiskScoringService, ControlEffectivenessService, MonteCarloService,
RegulatoryThresholdService. Cover boundaries, nulls, zero-control risks,
maximum scores, and every regulatory threshold edge.

ACCEPTANCE
□ Characterisation tests written and passing BEFORE other changes
□ One canonical column per concept; docs/schema/canonical-columns.md exists
□ RiskAppetiteService reads only real columns; tests prove it
□ $risk->auditTrail returns rows
□ One rating implementation, one effectiveness map, configurable aggregation
□ Reference codes unique per org; 50-way concurrency test passes
□ >85% coverage on the four calculation services
□ Full suite green on both mysql and sqlite
```

---

## WP-02 — Honest AI + Demo Blockers

**Depends on:** nothing (run parallel) · **Estimate:** 2 weeks

```
GOAL
Three things currently make the product unsellable to a Nigerian bank:
fabricated AI numbers, an RCSA worksheet that silently discards submissions,
and a "board report" that is a CSV file.

TASK 1 — REMOVE THE FABRICATED AI (do this first, today)
app/Services/AiDataService.php and
app/Http/Controllers/Risk/AiIntelligenceController.php generate user-facing
numbers with mt_rand() at ~12 call sites, plus hardcoded escalation
probabilities [0.87, 0.73, ...], hardcoded model metrics (accuracy 87.3,
precision 84.1, recall 89.7, f1 86.8, auc 0.912, model_version '2.4.1') and
peer benchmarks generated by mt_rand(2,5).

- IMMEDIATELY: gate the four routes (ai/predictive, ai/radar,
  ai/regulatory-pulse, ai/benchmarking) behind a config flag
  `features.ai_intelligence` defaulting to FALSE, so no customer environment
  can reach them.
- Then rebuild each on real data or remove it:
  * PREDICTIVE — replace with a computed forward view from the org's own data:
    trend extrapolation on residual scores from risk_assessments, overdue
    treatment velocity, KRI breach frequency, control test failure rate.
    Display method and inputs. NO invented accuracy metrics. If you cannot
    compute a confidence interval honestly, show a range and label it a
    projection, not a prediction.
  * RADAR — replace mt_rand categories with a real emerging-risk register
    (a new object) populated by users and, later, by WP-29 horizon scanning.
    Until WP-29, it is a manual register with a velocity and proximity score.
  * REGULATORY PULSE — drive from the real regulatory_circulars and
    regulatory_deadlines tables. Nothing invented.
  * BENCHMARKING — either remove entirely, or implement genuine cross-tenant
    anonymised aggregates with a documented k-anonymity floor (k>=5 orgs) and
    an explicit opt-in per organization. Do NOT ship fabricated peer values.
- Add a CI check that fails on mt_rand / rand( in app/Http/Controllers/,
  app/Services/Ai*, and any code path producing a user-facing figure.
  ALLOWLIST app/Services/MonteCarloService.php (lines 22, 34, 35 use mt_rand
  legitimately for Poisson and Box-Muller variates) and any future simulation
  engine — and switch those to a SEEDABLE generator so the WP-01
  characterisation tests can pin the output.

TASK 2 — FIX THE RCSA STUB
app/Http/Controllers/Risk/RcsaController.php ~line 266: storeWorksheet() has a
comment "// Process worksheet submission" and immediately redirects with a
success message. Submissions are silently discarded.
- Implement it: persist to campaign_responses (likelihood_score, impact_score,
  overall_score, rating, control_effectiveness, comments, questionnaire_data),
  update campaign_assignments status and completion counters, recalculate the
  campaign completion_pct, fire the appropriate domain event, and only then
  redirect.
- Add a feature test that submits a worksheet and asserts the rows exist.
- Audit every other controller for the same pattern:
  grep -n "// TODO\|// Process\|// Implement" app/Http/Controllers/ and fix or
  ticket each.

TASK 3 — REAL DOCUMENT OUTPUT
There is no PDF generation anywhere in the codebase. The board, regulatory and
executive reports render as ON-SCREEN BLADE VIEWS (ReportController lines 120,
256, 362) with no document output at all. The only file output in the system is
CSV, from ReportController::generateCustom() (line 519) and the 16
ExportController methods. Worse: generateCustom validates
`format in:pdf,excel,html,pptx` at line 496 and then collapses EVERY one of
them to CSV — the product currently promises four formats and delivers one.
- composer require barryvdh/laravel-dompdf spatie/laravel-pdf
  (or knplabs/knp-snappy with wkhtmltopdf if pixel fidelity matters more)
  and maatwebsite/excel for real XLSX.
- Create app/Services/DocumentRenderer with render(view, data, format) for
  pdf | xlsx | docx | pptx | csv.
- Build resources/views/reports/pdf/ templates with a proper cover page,
  organization branding from settings, table of contents, page numbers,
  headers/footers, and a generated-at + period-as-at stamp.
- Convert ReportController::executive, ::board and ::regulatory to produce PDF
  by default with CSV/XLSX as alternate formats.
- Fix DataImportController: it validates .xlsx/.xls but parses with fgetcsv,
  silently producing garbage. Use maatwebsite/excel for real spreadsheet parsing.
- Move report generation to a queued job (create app/Jobs/ — it does not exist)
  writing to generated_reports with a download route.

TASK 4 — BOARD PACK v1
- New: app/Services/BoardPackAssembler producing a single PDF from an ordered
  set of sections: cover, contents, executive summary, risk profile heat map,
  top risks table, appetite position, KRI dashboard, loss events summary,
  issues ageing, treatment progress, regulatory calendar, appendices.
- Sections are configurable per organization and reorderable.
- Output is versioned in generated_reports and downloadable.

ACCEPTANCE
□ features.ai_intelligence defaults false; the four routes 404 when disabled
□ grep for mt_rand in app/Http/Controllers/ and app/Services/Ai* returns
  nothing; MonteCarloService is allowlisted and now uses a seedable RNG
□ Every AI-surfaced number is traceable to a real query — document the mapping
□ RCSA worksheet submission persists; feature test proves it
□ Board report downloads as a branded, paginated PDF
□ XLSX import parses a real .xlsx correctly
□ Report generation runs on a queue with progress
```

---

# PHASE 1 — PLATFORM CORE (weeks 5–16)

---

## WP-03 — Object Graph Core

**Depends on:** WP-00, WP-01 · **Estimate:** 5 weeks · **The architectural bet**

```
GOAL
Build the metadata-driven object graph — the "digital twin of the organisation"
that is Corporater's real moat. Every governed thing becomes a typed node in one
graph with typed relationships. This must be ADDITIVE: existing typed tables keep
their columns; `objects` is a graph INDEX alongside them, not a replacement.

CONTEXT — WHAT ALREADY EXISTS
There is an embryonic version: entity_types (organization_id, code, name, level
0-4, icon, color) + entities (entity_type_id, parent_id self-tree, entity_code,
owner_id, delegate_owner_id, status, regulatory_frameworks json,
risk_appetite_level, category_appetites json, metadata json), with entity_id on
risks, controls, issues, loss_events and key_risk_indicators.
There is ALSO a parallel fixed org model: organizations → business_units
(self-tree) → business_processes, referenced by business_unit_id on risks,
controls, issues, loss_events, campaign_assignments and users.
These two must be unified.

TASK 1 — METADATA TABLES
Create migrations for:

  object_types(id, organization_id NULLABLE [null = system type], code, name,
    plural_name, category ENUM(org_node|governance|assessment|reference),
    parent_type_id NULLABLE [inheritance], icon, color, level_hint,
    is_system BOOL, is_node_type BOOL, allowed_child_type_ids JSON,
    default_lifecycle_id, code_prefix, sort_order, timestamps, softDeletes)
    UNIQUE(organization_id, code)

  object_attributes(id, object_type_id, code, label, data_type ENUM(string|text|
    int|decimal|money|bool|date|datetime|enum|multi_enum|user|object_ref|json|
    formula), is_required, is_unique, default_value, validation JSON,
    enum_options JSON, ref_object_type_id, formula TEXT, section, sort_order,
    help_text, is_pii, is_system, timestamps)
    UNIQUE(object_type_id, code)

  objects(id, organization_id, object_type_id, node_id NULLABLE [FK objects — the
    owning org-graph node], code, name, description TEXT, owner_id,
    delegate_owner_id, lifecycle_state, status, effective_from, effective_to,
    attributes JSON, hierarchy_path VARCHAR(1024) INDEXED, hierarchy_depth,
    parent_id NULLABLE [FK objects], sort_order, source_model_type,
    source_model_id, created_by, updated_by, version INT, timestamps, softDeletes)
    UNIQUE(organization_id, object_type_id, code)
    INDEX(organization_id, object_type_id), INDEX(node_id),
    INDEX(hierarchy_path(191)), INDEX(source_model_type, source_model_id)

  object_relationship_types(id, organization_id NULLABLE, code, name,
    inverse_code, from_type_ids JSON, to_type_ids JSON,
    cardinality ENUM(one_to_one|one_to_many|many_to_many), has_weight BOOL,
    attribute_schema JSON, is_system, timestamps)
    Seed: mitigates, owns, supports, depends_on, maps_to, assures, reports_to,
          assessed_in, threatens, derives_from, causes, results_in,
          governed_by, tested_by, delivered_by

  object_relationships(id, organization_id, relationship_type_id,
    from_object_id, to_object_id, weight DECIMAL(8,4) DEFAULT 1,
    attributes JSON, effective_from, effective_to, created_by, timestamps)
    UNIQUE(relationship_type_id, from_object_id, to_object_id)
    INDEX(from_object_id), INDEX(to_object_id)

  object_lifecycles(id, organization_id NULLABLE, object_type_id, code, name,
    states JSON [{code,name,color,is_initial,is_terminal,allowed_transitions[],
    required_permission,required_workflow_id}], is_system, timestamps)

  object_versions(id, object_id, version, snapshot JSON, changed_by, changed_at,
    change_reason, source ENUM(ui|api|import|job|migration))
    INDEX(object_id, version)

TASK 2 — SEED THE TYPE REGISTRY
Seed object_types as system types (organization_id null):
  ORG NODES:   Enterprise, LegalEntity, Division, BusinessUnit, Department,
               Branch, Process, System, Application, Asset, Product, Channel,
               Geography, Project, Vendor, ImportantBusinessService
  GOVERNANCE:  Risk, Opportunity, Control, KeyRiskIndicator, KeyPerformanceIndicator,
               Objective, TreatmentPlan, Issue, Action, LossEvent, NearMiss,
               RiskAppetite, Policy, Obligation, Scenario, Audit, Finding,
               EmergingRisk, AiSystem
  ASSESSMENT:  RiskAssessment, AssessmentCampaign, ControlTest, Questionnaire,
               MaturityAssessment, DPIA, BIA
  REFERENCE:   RiskCategory, Taxonomy, Framework, Requirement, Unit, Currency
Seed default lifecycles for Risk, Control, Issue, TreatmentPlan, LossEvent,
Policy, Obligation, Audit.

TASK 3 — HasObjectIdentity TRAIT
Create app/Models/Concerns/HasObjectIdentity.php. On a model using it:
  - created/updated → upsert a row in `objects` mirroring
    (organization_id, object_type_id resolved from a static $objectTypeCode,
     node_id resolved from entity_id/business_unit_id, code from the model's
     reference column, name, description, owner_id, lifecycle_state from status,
     source_model_type, source_model_id, hierarchy_path)
  - deleted → soft delete the objects row
  - expose ->object() relation and ->relate($code, $target, $attrs = [])
    / ->related($code) helpers.
Apply to: Risk, Control, KeyRiskIndicator, Issue, LossEvent, NearMiss,
TreatmentPlan, RiskAppetite, AssessmentCampaign, ControlTest, Entity,
BusinessUnit, BusinessProcess, RiskCategory, QuantificationScenario.

TASK 4 — UNIFY THE TWO ORG MODELS
- Write a migration that creates `objects` rows for every business_unit
  (type BusinessUnit) and business_process (type Process), and for every
  existing entity (mapping entity_type to the closest seeded org-node type).
- Where an entity and a business_unit represent the same real thing, merge them
  using a mapping table the migration produces for review — do NOT guess
  silently; log every merge and every unmatched row.
- Set objects.parent_id and hierarchy_path from the merged tree; write a
  RebuildHierarchyPaths job.
- Keep business_unit_id and entity_id on the domain tables. Add a resolved
  node_id. Update controllers to read node_id, with a fallback to the legacy
  columns for one release.
- Add tests: the merged tree has no cycles, every node has a correct
  hierarchy_path, and every risk resolves to exactly one node.

TASK 5 — MIGRATE PIVOTS TO TYPED RELATIONSHIPS
Migrate into object_relationships (keeping the old tables as read-only views
for one release):
  risk_control_mapping   → 'mitigates'  (control → risk), weight = control_weight,
                           attributes = {is_key_control, mapping_rationale}
  risk_related_risks     → 'derives_from' / correlation type
  loss_event_controls    → 'failed_control' with {failure_type, failure_description}
  regulatory_risk_mapping→ 'maps_to' (risk → requirement)
  risk_kri_mapping       → 'monitored_by' (risk → KRI)
  near_misses.converted_loss_event_id → 'converted_to'

TASK 6 — GRAPH QUERY SERVICE
app/Services/Graph/GraphQueryService with:
  - descendants(objectId, types = [], maxDepth = null)
  - ancestors(objectId)
  - related(objectId, relationshipCode, direction, depth = 1)
  - traverse(objectId, path = ['supports','depends_on'], maxDepth)
  - rollUp(objectId, measureCode, periodId, aggregation)
All must respect tenancy and node-scoped authorization. Benchmark: descendants()
on a 50,000-node tree under 200 ms.

TASK 7 — CUSTOM FIELD RENDERING
A Livewire <x-dynamic-form :object-type="..." :model="..."> that renders and
validates fields from object_attributes into objects.attributes JSON, with
conditional visibility and role-conditional rendering.

ACCEPTANCE
□ Every model listed in Task 3 has a corresponding objects row after save
□ The unified org tree has no cycles; hierarchy_path is correct for every node
□ Merge report produced for human review; no silent merges
□ Every migrated pivot is queryable through object_relationships
□ GraphQueryService::descendants() < 200 ms on 50k nodes
□ A custom attribute added via object_attributes renders, validates and persists
□ No existing feature regressed — full suite green
```

---

## WP-04 — Period & Measure Engine

**Depends on:** WP-03 · **Estimate:** 4 weeks

```
GOAL
Make time a first-class dimension. `(measure × object × period × scenario) →
value`. This is what produces trends, "as at" queries, assessment cycles, an
animated heat map, and a board pack that reconciles. Every Corporater screenshot
depends on the period selector in the top bar.

TASK 1 — PERIOD MODEL
  period_calendars(id, organization_id, code, name, fiscal_year_start_month,
    is_default, timestamps)
  periods(id, organization_id, calendar_id, code, type ENUM(day|week|month|
    quarter|half|year|custom), name, start_date, end_date, parent_period_id,
    is_closed, closed_at, closed_by, timestamps)
    UNIQUE(calendar_id, code), INDEX(organization_id, start_date, end_date)
- Seed months, quarters, halves and years for the current fiscal year ±3.
- app/Services/PeriodService: current(), resolve(date), previous(period),
  range(from, to), close(period) [locks measure_values], reopen(period)
  [requires permission + audit].
- Bind a selected period per request from session/query param, exposed to all
  views as $selectedPeriod. Add the top-bar period selector with ‹ › arrows,
  matching the Corporater chrome.

TASK 2 — MEASURE MODEL
  units(id, code, name, symbol, category ENUM(currency|count|pct|time|mass|
    energy|ratio|custom), base_unit_id, conversion_factor, timestamps)
  measures(id, organization_id, code, name, description, object_type_id,
    measure_kind ENUM(kri|kpi|risk_score|control_eff|esg|financial|capital|
    quality), unit_id, aggregation ENUM(sum|avg|last|max|min|weighted_avg|count),
    polarity ENUM(higher_better|lower_better|target_band), decimal_places,
    formula TEXT, is_derived, source ENUM(manual|import|api|calculated),
    frequency, owner_id, is_active, timestamps)
    UNIQUE(organization_id, code)
  measure_values(id, organization_id, measure_id, object_id, period_id,
    scenario ENUM(actual|target|budget|forecast|stress|plan|baseline),
    value DECIMAL(28,6), currency_code CHAR(3) NULLABLE, fx_rate_used
    DECIMAL(18,8) NULLABLE, status ENUM(draft|submitted|approved|locked),
    rag_band, entered_by, entered_at, source, evidence_ref, note, timestamps)
    UNIQUE(measure_id, object_id, period_id, scenario, currency_code)
    INDEX(organization_id, period_id, measure_id)
    INDEX(object_id, measure_id, period_id)
  measure_thresholds(id, organization_id, measure_id, object_id NULLABLE
    [null = default], effective_from, effective_to, bands JSON
    [{code,label,color,min,max,min_formula,max_formula}], direction,
    approved_by, approved_at, supersedes_id, timestamps)
  measure_breaches(id, organization_id, measure_id, object_id, period_id,
    breached_at, band_from, band_to, value, threshold_value, severity,
    status ENUM(open|acknowledged|resolved|false_positive), acknowledged_by,
    acknowledged_at, resolved_at, linked_risk_id, linked_issue_id, root_cause,
    note, timestamps)
  fx_rates(id, organization_id NULLABLE, from_currency, to_currency, rate_date,
    rate_type ENUM(cbn_official|nafem|parallel|internal|custom),
    rate DECIMAL(18,8), source, captured_at, timestamps)
    UNIQUE(organization_id, from_currency, to_currency, rate_date, rate_type)

TASK 3 — MIGRATE KRIs INTO THE MEASURE ENGINE
- For each key_risk_indicators row, create a `measures` row (measure_kind=kri)
  preserving kri_code as measures.code, and a measure_thresholds row from
  green/amber/red_threshold_min/max + threshold_direction.
- Migrate kri_measurements → measure_values (scenario=actual), resolving
  measurement_date to a period.
- Keep key_risk_indicators as a view/facade for one release; update KriController
  to read/write through the measure engine.
- CRITICAL: create measure_breaches from CheckKriBreaches going forward — today
  breaches are computed nightly and survive only as notifications, so there is
  no breach register, no acknowledgement and no MTTR. Backfill what you can from
  notifications_log.

TASK 4 — PERIOD-STAMP RISK SCORES
- Register measures: risk.inherent_score, risk.residual_score, risk.target_score,
  risk.inherent_likelihood, risk.inherent_impact, risk.residual_likelihood,
  risk.residual_impact, risk.control_effectiveness, risk.financial_exposure.
- On every RiskAssessment approval, write measure_values for the assessment's
  period. Backfill from existing risk_assessments using assessment_date.
- Keep risks.inherent_score etc. as the denormalised CURRENT value for fast list
  rendering, updated from the latest approved period.
- Add RiskRepository::asOf(periodId) returning the register as at that period.
- Add tests: assess a risk in Q1 and Q2; asOf(Q1) returns the Q1 values.

TASK 5 — FORMULA THRESHOLDS + INFLATION RE-BASELINING (the Nigerian feature)
- Support formula-valued band bounds in measure_thresholds.bands, e.g.
  {"max_formula": "0.005 * @measure('capital.total_qualifying')"} or
  {"max_formula": "50000000 * @cpi_index('2026-01')"}.
- Build app/Services/FormulaEvaluator with a safe expression language
  (symfony/expression-language) exposing @measure(), @cpi_index(), @fx(),
  @capital(), @revenue(). NO eval().
- Scheduled job at period close: re-evaluate formula thresholds, and where the
  computed band differs from the active band by more than a configurable
  tolerance, raise a RE-BASELINING APPROVAL TASK (WP-06 workflow). On approval,
  write a NEW effective-dated measure_thresholds row with supersedes_id set.
  The old band is retained, never overwritten.
- This directly fixes the "everything is High" drift caused by Nigerian
  inflation. Add tests covering a 16% CPI move.

TASK 6 — MULTI-CURRENCY
- Every monetary measure_value carries currency_code and, if converted,
  fx_rate_used and the rate_type.
- app/Services/CurrencyService::convert(amountMinor, from, to, date, rateType)
  with a configurable organization default rate_type (default cbn_official).
- Add a reporting_currency to organizations.settings. Group roll-ups convert
  at the period-end rate and RECORD the rate used.
- Add a CBN rate fetcher job (scheduled) with manual override and an audit trail.

ACCEPTANCE
□ A period selector in the top bar changes every dashboard, register and score
□ risk register asOf(Q1 2026) differs correctly from asOf(Q2 2026)
□ KRIs read and write through measure_values; kri_measurements is backfilled
□ measure_breaches has rows; acknowledgement and MTTR are computable
□ A threshold defined as "0.5% of qualifying capital" re-evaluates at period
  close and raises an approval task; approving writes a new effective-dated band
□ A loss event in USD rolls up into an NGN group report at the recorded rate
□ Query performance: 12-period trend for 5,000 risks under 500 ms
```

---

## WP-05 — Metadata & Configuration Engine

**Depends on:** WP-03 · **Estimate:** 4 weeks

```
GOAL
"Configure, don't code." A domain SME must be able to add an object type, a
field, a relationship, a lifecycle state and a validation rule without an
engineer and without a release — and that configuration must be promotable from
dev to test to prod with a diff and a rollback.

TASK 1 — OBJECT TYPE BUILDER (admin UI, Livewire)
- CRUD for object_types with inheritance, allowed child types, icon/colour,
  code prefix, and lifecycle assignment.
- CRUD for object_attributes with all data types, validation rules, enum
  options, conditional visibility rules, calculated fields (formula), sections
  and ordering.
- CRUD for object_relationship_types with from/to type constraints, cardinality
  and relationship attribute schema.
- CRUD for object_lifecycles: visual state machine editor (states, transitions,
  required permission per transition, required workflow per transition).
- Guardrails: system types cannot be deleted; an attribute in use cannot change
  data_type without an explicit migration path; deleting a relationship type
  with instances requires confirmation and archives them.

TASK 2 — DYNAMIC FORM & VIEW RENDERER
- <x-dynamic-form> renders create/edit forms from object_attributes with
  sections, conditional visibility, role-conditional rendering and mobile
  layout variants.
- <x-dynamic-detail> renders a read view with the same metadata.
- Target: replace at least five of the hand-rolled create/edit view pairs
  (start with controls, KRIs, treatments) to prove the pattern, then migrate
  the rest in later WPs.

TASK 3 — SCORING PROFILES (retire the hardcoded 5×5)
The 5×5 matrix is hardcoded in three places: RiskScoringService:103-117,
AnalysisController:43-57 and heatmap.blade.php:77. organizations.settings->
risk_settings and ->risk_thresholds are written by OrganizationSettingsController
and read by NOTHING.
- Create scoring_profiles(id, organization_id, code, name, applies_to JSON
  [node_ids, object_type_ids, risk_type codes], likelihood_scale JSON
  [{value,label,definition,probability_min,probability_max}], impact_scale JSON
  [{value,label,definition,financial_min,financial_max,currency}],
  impact_dimensions JSON, impact_aggregation ENUM(max|weighted|average|worst_two),
  dimension_weights JSON, rating_bands JSON [{code,label,color,min,max}],
  residual_formula, matrix_rows, matrix_cols, is_default, effective_from,
  approved_by, timestamps).
- Rewrite RiskScoringService to resolve a ScoringProfile per organization / node
  / risk type and use it for EVERY calculation. Delete every hardcoded constant.
- Make the heat map render matrix_rows × matrix_cols (3×3 to 10×10).
- Migrate existing organizations to a seeded 5×5 default profile so nothing
  changes on upgrade. Add tests proving score parity before and after.

TASK 4 — CONFIGURATION AS A VERSIONED ARTEFACT
  config_bundles(id, organization_id, code, name, version, description,
    payload JSON, checksum, exported_at, exported_by, source_environment,
    timestamps)
- Export: object types, attributes, relationship types, lifecycles, scoring
  profiles, measures, thresholds, workflow definitions, dashboards, widgets,
  report templates, content pack bindings.
- Import with DRY-RUN by default producing a structured DIFF (added, changed,
  removed, conflicting) before anything is written.
- Apply inside a transaction with a rollback point. Record every apply in an
  immutable log.
- Artisan: config:export, config:diff, config:import --dry-run, config:rollback.
This is where most "configurable" platforms fail. Do it properly.

ACCEPTANCE
□ A new object type with 5 custom fields, a relationship and a lifecycle can be
  created entirely through the UI with no code change
□ Five existing create/edit view pairs replaced by <x-dynamic-form>
□ An organization can switch to a 4×4 or 6×6 matrix and every heat map, score
  and rating updates coherently
□ Existing 5×5 organizations produce identical scores after migration (test)
□ config:export → config:import --dry-run on a clean environment produces a
  correct diff and applies cleanly
```

--- Start Here ---

## WP-06 — Workflow Engine v2

**Depends on:** WP-03 · **Estimate:** 4 weeks

```
GOAL
There are currently THREE approval mechanisms: approval_requests (generic
maker-checker), per-module approved_by/status columns (risk assessments,
treatment plans, control tests, loss events), and workflow_instances (a linear
JSON stage engine whose escalation_rules are stored and never read, and whose
delegate/escalate/return actions are logged but change nothing). Replace all
three with one engine.

TASK 1 — ENGINE SCHEMA
Extend workflow_definitions with: version, is_published, object_type_id,
definition JSON (nodes[] + edges[]), trigger ENUM(manual|on_create|
on_transition|on_schedule|on_event), trigger_config JSON, scope_filter JSON,
bpmn_xml TEXT NULLABLE.

Node types: start | task | approval | exclusive_gateway | parallel_gateway |
join | timer | service_task | sub_process | escalation | notification | end
Per node: assignee_rule ENUM(user|role|owner|delegate|manager|
relationship_traversal|group|expression), assignee_config JSON, sla_hours,
escalate_after_hours, escalate_to, on_timeout ENUM(escalate|auto_approve|
auto_reject|notify), required_fields JSON, form_id, allow_delegate,
allow_return, condition_expression.

Extend workflow_instances with: context JSON, current_nodes JSON (array —
parallel execution), sla_due_at, breached_at, correlation_key.

New workflow_tasks(id, organization_id, instance_id, node_code, assignee_id,
assignee_role, status ENUM(pending|in_progress|completed|delegated|escalated|
cancelled|expired), due_at, escalated_at, completed_at, outcome, comments,
delegated_from, delegated_to, form_data JSON, timestamps)
  INDEX(assignee_id, status), INDEX(instance_id)

TASK 2 — ENGINE IMPLEMENTATION
app/Services/Workflow/WorkflowEngine:
  start(definition, subject, context) → instance
  advance(task, outcome, payload) → evaluates edges, resolves conditions,
    forks on parallel gateways, joins, creates the next tasks
  delegate(task, toUser, reason) — MUST actually reassign, not just log
  escalate(task, reason) — MUST actually change assignee and status
  returnForRework(task, toNode, reason) — MUST move the instance back
  cancel(instance, reason)
Scheduled SLA sweeper: mark breaches, run escalations, honour on_timeout,
send notifications. Finally read workflow_definitions.escalation_rules.

TASK 3 — VISUAL DESIGNER
Livewire drag-and-drop designer: place nodes, draw edges, set assignee rules,
SLAs, conditions and forms. Validate before publish (unreachable nodes,
missing end, unbalanced gateways). Version on publish; running instances
continue on their pinned version.

TASK 4 — MIGRATE ALL APPROVALS ONTO THE ENGINE
Create and seed definitions for: Risk Assessment approval, Treatment Plan
approval, Control Test review, Loss Event approval (multi-stage, matching the
existing loss_event_approvals stage model), Issue closure approval, Risk
Appetite approval, Risk Acceptance approval, Threshold re-baselining approval
(from WP-04), Policy approval, ICAAP sign-off.
- Migrate open approval_requests rows into workflow_instances + workflow_tasks.
- Rewrite the per-module approve/reject controller methods to call the engine.
- Keep the per-module approved_by/approved_at columns updated by the engine for
  backward compatibility for one release.
- Preserve the existing Gates as the permission layer the engine consults.

TASK 5 — MY TASKS FOUNDATION
An indexed query returning every open workflow_task for a user, plus overdue
counts, feeding "My Responsibilities" in WP-08.

ACCEPTANCE
□ One engine drives approvals in at least six modules
□ Parallel branches fork and join correctly (test)
□ SLA breach escalates and reassigns — not just logs (test)
□ Delegate actually changes the assignee (test)
□ Return-for-rework moves the instance back (test)
□ approval_requests has no new writes; open rows migrated
□ Designer publishes a valid definition; invalid definitions are rejected
```

---

## WP-07 — API, Jobs & Integration Foundation

**Depends on:** WP-03 · **Estimate:** 4 weeks

```
GOAL
There is no routes/api.php, no Sanctum, no JSON resources, no OpenAPI spec, no
webhooks, no app/Jobs/ directory, and Monte Carlo runs synchronously inside an
HTTP request. Every integration and AI claim in the roadmap has no substrate.

TASK 1 — QUEUE INFRASTRUCTURE
- Switch QUEUE_CONNECTION to redis; install laravel/horizon.
- Create app/Jobs/ and move to queued jobs: Monte Carlo simulation (with
  progress writes to simulation_runs.status and a cancel flag), data imports,
  exports, report generation, board pack assembly, notification fan-out, LLM
  calls, connector syncs, threshold re-baselining, hierarchy path rebuilds.
- Add a job progress table and a Livewire progress component.
- CRITICAL: MonteCarloService currently runs 10,000 iterations × N scenarios in
  the request cycle. Make it a job. simulation_runs already models
  status/error_message/started_at/completed_at/runtime_seconds — honour them.

TASK 2 — REST API
- Create routes/api.php with /api/v1.
- Auth: Sanctum personal access tokens + OAuth2 client credentials for
  machine-to-machine. Scope tokens by permission.
- Resource controllers over the ENTIRE object model: objects (generic, by type),
  plus first-class endpoints for risks, controls, kris, measures, measure_values,
  issues, loss-events, treatments, assessments, campaigns, obligations,
  workflows, tasks, reports, periods.
- JSON:API-shaped resources: filtering (?filter[status]=open), sorting,
  sparse fieldsets, includes for relationships, cursor pagination.
- Idempotency-Key header support on POST/PUT.
- Rate limiting per token. Versioning with a documented deprecation policy.
- Generate an OpenAPI 3.1 spec (dedoc/scramble or zircote/swagger-php) served
  at /api/docs with a Swagger/Redoc UI.
- Every endpoint enforces tenancy and node-scoped authorization. Add a test that
  fails if any API route lacks an authorization check.

TASK 3 — WEBHOOKS
  webhook_subscriptions(id, organization_id, url, secret, events JSON,
    is_active, created_by, timestamps)
  webhook_deliveries(id, subscription_id, event, payload JSON, attempt,
    status_code, response_body, delivered_at, next_retry_at)
- Signed payloads (HMAC-SHA256 in an X-Signature header), exponential backoff,
  a delivery log UI and manual replay.
- Publish events from the existing domain event set plus object lifecycle
  transitions and workflow task events.

TASK 4 — CONNECTOR FRAMEWORK
- app/Integrations/Contracts/Connector: describe(), authenticate(),
  testConnection(), pull(since), push(payload), fieldMap().
- connectors(id, organization_id, type, name, config JSON [encrypted],
  credentials [encrypted], schedule, field_map JSON, last_run_at, last_status,
  is_active)
  connector_runs(id, connector_id, started_at, finished_at, status,
  records_read, records_written, errors JSON)
- Dry-run mode producing a reconciliation report before writing.
- Ship two reference connectors to prove the framework:
  (a) a generic CSV/SFTP connector, (b) a generic REST/JSON connector with
  JSONPath field mapping.
- Wire KRI automation: key_risk_indicators.is_automated and automation_config
  are dead columns today — a scheduled connector run must now populate
  measure_values for automated measures.

TASK 5 — MCP SERVER
- Expose an MCP server so external AI agents (and our own, in WP-29) can query
  the platform: list_object_types, query_objects, get_object, traverse_graph,
  get_measure_series, list_my_tasks — all read-only initially, all enforcing
  the calling identity's permissions, all returning citations (object ids).
- Add governed write tools behind an explicit approval scope.

ACCEPTANCE
□ Monte Carlo runs as a job with progress and cancel; no long HTTP requests
□ /api/v1 covers every object type; OpenAPI spec published and valid
□ API authorization test passes — no unguarded endpoints
□ A webhook fires on risk.created and retries on failure
□ A CSV connector pulls KRI values into measure_values on a schedule
□ MCP server responds to query_objects with permission-correct results
```

---

## WP-08 — Widget & Dashboard Engine + Business HQ + My Responsibilities

**Depends on:** WP-03, WP-04 · **Estimate:** 5 weeks · **This is the demo**

```
GOAL
Replace the single 48.6 KB fixed dashboard with Corporater's model: one widget
primitive, N contexts. Plus the two navigation surfaces that define their UX —
Business HQ (per-node landing page) and My Responsibilities (personal work queue).

TASK 1 — WIDGET ENGINE
  widget_definitions(id, organization_id NULLABLE, code, name, description,
    widget_type, object_type_id, query JSON {filters, relationship_joins,
    group_by, aggregate, sort, limit}, measure_id, period_binding
    ENUM(selected|relative|fixed|range), period_config JSON,
    context_binding ENUM(inherit_node|inherit_subtree|fixed_node|user_scope|
    global), visualisation JSON, drilldown JSON, min_w, min_h, is_system,
    timestamps)
  dashboards(id, organization_id, code, name, object_type_id, role_ids JSON,
    is_default_for_role, tabs JSON [{code,label,layout:[{widget_id,x,y,w,h,
    overrides}]}], is_published, version, timestamps)
  dashboard_user_prefs(id, user_id, dashboard_id, layout_override JSON,
    saved_filters JSON, timestamps)

CONTEXT BINDING IS THE POINT: a widget with context_binding=inherit_subtree
placed on the Group HQ page shows group data; the SAME widget definition on a
BU page shows that BU's data. One definition, N contexts. Implement this as a
scope injected into the widget's query at render time from the page's node.

TASK 2 — SHIP THESE WIDGET TYPES (they map 1:1 to the Corporater screenshots)
  kpi_tile          — big number + label + optional sparkline and delta
  register          — paginated table with inline +, search, edit and per-row ⋮
  heatmap           — Probability × Consequence, configurable dimensions from
                      the scoring profile, COUNT BADGE IN EACH CELL, click-through
                      to the filtered register
  opportunity_heatmap — inverse polarity, blue palette, reversed axis
                      (ISO 31000's bidirectional definition — we have no
                      opportunity model at all today; see WP-10)
  stacked_bar_bands — risk per organizational unit, FIVE bands
                      (Deep Red / Red / Orange / Amber / Green)
  pareto            — bars + cumulative % line + explicit "Drill Down" link
  grouped_bar_3     — Inherent vs Residual vs Planned/Future residual
  bubble            — x = control effectiveness, y = exposure (L×C),
                      size = magnitude, label = risk name
  stacked_area      — risk development over time by priority, 12 periods
  trend_stacked_bar — quarterly, split Increasing / Constant / Decreasing
  activity_table    — name, responsible, start, end, progress bar, RAG status
                      (used twice: ON TRACK and OFF TRACK)
  measure_table     — measure, type, responsible, implemented ✓, status glyph
                      (used for CONTROLS and NOT IMPLEMENTED / FINDINGS)
  cumulative_line   — control measures implemented %, monthly
  donut             — by priority / by category
  bar_by_type       — count by object type
  tornado           — financial KPI distribution per year (EBITDA/FCF/NPV)
  lec_curve         — loss exceedance curve (populated in WP-18)
  gauge, treemap, timeline, network

Follow the dataviz standards: one coherent visual system, accessible in light
and dark, consistent categorical palette, RAG bands that survive colour-blind
rendering, no chartjunk.

TASK 3 — DASHBOARD BUILDER
Drag-and-drop grid (12-column), resize, per-widget config overrides, tabs,
role-based composition, save as template, publish/unpublish, per-user layout
override. Every panel gets the Corporater ⋮ menu: refresh, configure, export,
drill down, remove.

TASK 4 — BUSINESS HQ
- Route /hq/{object} rendering the dashboard bound to that node's object type,
  with the breadcrumb trail (Business HQ › Enterprise Risk Management › …),
  the tab set from the dashboard definition, and the global period selector.
- Reproduce the Corporater ERM tab set as a seeded dashboard:
  Dashboard | Risk Category Dashboard | Top Risk | Financial Risk | Credit Risk |
  Risk Effectiveness | Risk Register | Reports
- Reproduce the assessment-context tab set:
  Dashboard | <Perspective Register> | Risk Treatment Overview |
  Risk Development Over Time | Risk Register | Trends | Report
- A tree navigator down the left for the org graph; clicking a node re-renders
  every widget in that node's context.

TASK 5 — MY RESPONSIBILITIES (the adoption feature)
- Route /my — the default landing page for non-risk-professional roles.
- Computed from ownership edges in the object graph plus open workflow_tasks:
  * Tasks awaiting me (approvals, reviews, sign-offs) with due dates and SLA
  * Objects I own that need action (overdue treatment, unassessed risk,
    control test due, KRI reading due, policy to attest, questionnaire to answer)
  * Breaches on measures I own
  * Delegations to and from me
- Group by "due today / this week / overdue", show an estimated completion time,
  and make every item completable inline in under 3 minutes.
- Digest email and Teams/Slack card with deep links.
THE HARDEST PROBLEM IN ERM SOFTWARE IS FIRST-LINE PARTICIPATION, NOT RISK-MANAGER
UX. This page is the single highest-leverage feature for adoption. Treat it that way.

TASK 6 — GLOBAL SEARCH
Cross-object search over objects.name, code, description and attributes,
permission-filtered, with type-ahead and jump-to.

ACCEPTANCE
□ The same widget definition on two different nodes returns two correct results
□ All 20 widget types render from real data
□ The heat map shows a count badge per cell and drills through to the register
□ Both the risk heat map and the opportunity heat map render
□ A dashboard can be built by drag-and-drop and published to a role
□ /hq/{node} renders the ERM tab set with the period selector working
□ /my shows a user's real tasks and owned objects; median completion < 3 min
□ Global search returns permission-correct results
```

---

## WP-09 — Front-end System & Design System

**Depends on:** WP-08 · **Estimate:** 4 weeks

```
GOAL
Kill the dual front-end stack, build the shared components the app has never
had, and lay the i18n and accessibility foundation.

TASK 1 — ONE BUILD
- Vite is configured and @vite appears in ZERO Blade files; assets come from
  CDNs. (WP-00 fixes the CDN issue; this task completes the migration.)
- Install Livewire 3. Migrate layouts/app.blade.php and all partials to the
  Vite build. Enable Livewire SPA navigation.
- Delete resources/views/vendor/pagination/bootstrap-*.blade.php and
  semantic-ui.blade.php (residue from the Bootstrap→Tailwind conversion).

TASK 2 — THE DATA GRID
components/data-table.blade.php is 520 bytes; ~25 index views hand-roll their
own filtering and pagination.
Build <x-data-grid> (Livewire) with: server-side filter/sort/paginate, column
chooser, saved views per user, bulk actions, inline edit, row selection,
per-row action menu (the ⋮), export to CSV/XLSX, empty and loading states,
mobile card fallback, and keyboard navigation.
Migrate all ~25 index views onto it.

TASK 3 — COMPONENT LIBRARY
Buttons, inputs, selects (searchable, async), date and period pickers, money
input (currency-aware, minor-unit safe), rich text, file upload with drag-drop
and resumable chunking, modals, drawers, tabs, breadcrumbs, toasts, badges,
RAG chips, progress bars, avatars, empty states, skeleton loaders, tree view,
matrix/heatmap cell, sparkline. Document in a /design-system route.

TASK 4 — CHART LAYER
One wrapper over the chart library implementing every widget_type from WP-08,
on the dataviz standard: single categorical palette, semantic RAG palette,
accessible in light and dark, colour-blind-safe, consistent axis and legend
treatment, tooltips with formatted values and units, and drill-through callbacks.

TASK 5 — i18n
- Create lang/en/ and extract every hardcoded string (currently 28 __() calls
  across 142 views; ₦ hardcoded in 28 views).
- Locale-driven number, currency and date formatting — never a hardcoded symbol.
- Add lang/fr/ scaffolding for francophone Africa. RTL-ready CSS.
- Add a CI check that fails on new hardcoded user-facing strings in Blade.

TASK 6 — ACCESSIBILITY TO WCAG 2.2 AA
Semantic landmarks, focus management, keyboard operability on every
interaction, ARIA on custom components, 4.5:1 contrast, visible focus rings,
reduced-motion support. Add axe-core to CI.

ACCEPTANCE
□ No CDN script tags; everything through Vite
□ All ~25 index views use <x-data-grid>
□ /design-system documents every component
□ Zero hardcoded user-facing strings; French locale switchable
□ axe-core reports no critical or serious violations
□ Lighthouse accessibility >= 95 on the five main pages
```

---

# PHASE 2 — CORPORATER PARITY (weeks 13–24)

---

## WP-10 — Risk Domain Uplift

**Depends on:** WP-04, WP-05 · **Estimate:** 4 weeks

```
GOAL
Bring the risk domain itself to ISO 31000 / COSO ERM 2017 conformance and
Corporater parity.

TASK 1 — CAUSE → EVENT → CONSEQUENCE
Today a risk is a title plus scores. ISO 31000 requires structure.
- Add risk_causes and risk_consequences as typed objects related to the risk
  ('causes' and 'results_in' relationship types from WP-03).
- Each cause carries likelihood and existing preventive barriers; each
  consequence carries impact by dimension and existing mitigative barriers.
- This is also the data model the editable bow-tie needs (WP-11).

TASK 2 — OPPORTUNITY MODELLING
ISO 31000 defines risk as the effect of uncertainty on objectives — which is
bidirectional. Corporater ships an AGGREGATED SCENARIO OPPORTUNITIES heat map
beside the risk one. We have no opportunity model at all.
- Add an Opportunity object type with upside likelihood and benefit scales,
  its own appetite, its own treatment ("exploit / enhance / share / accept"),
  and its own heat map (inverse polarity, blue palette).
- Allow a single uncertainty to carry both a downside and an upside assessment.

TASK 3 — REAL TARGET RISK
risks.target_rating is a STRING LABEL ONLY — no target likelihood, impact or
score — so target risk cannot be plotted on a matrix.
- Add target_likelihood, target_impact, target_score, target_date.
- Add a THIRD value everywhere: Inherent · Residual (current) · Planned/Future
  residual (what the approved treatment plan is expected to deliver), derived
  from treatment_plans.expected_residual_likelihood/impact.
- All three render in the grouped_bar_3 widget, matching Corporater's
  "INHERENT VS RESIDUAL VS PLANNED (PER ASSET)".

TASK 4 — MULTIPLE SIMULTANEOUS TAXONOMIES
Today: risks.category_id points to one risk_categories row; risk_taxonomies is
an orphan table referenced by nothing.
- Allow a risk to carry N classifications via object_relationships 'classified_as'
  — e.g. Basel L1 event type AND internal category AND strategic theme AND ESG
  pillar AND CBN ORMS category, simultaneously.
- Merge risk_taxonomies (a disconnected parallel tree with its own live CRUD
  surface) into the unified taxonomy service, preserving its existing UI route.
- Bulk re-classification tool with preview.

TASK 5 — APPETITE REWRITE
Today: risk_appetite keys to risk_category_id only; one tolerance band;
RiskAppetiteService reads five columns that do not exist.
- Rebuild appetite as a first-class object linked to OBJECTIVES and to NODES,
  not just categories.
- Structure: appetite statement → tolerance band → capacity limit (three tiers).
- Cascade: group appetite decomposes to BU-level limits with an allocation model
  and a check that the sum of children does not exceed the parent.
- Currency-denominated appetite with a stated probability of exceedance
  ("we accept no more than a 5% chance of annual operational losses exceeding
  ₦2bn") — this is what makes appetite meaningful once WP-18 lands.
- Per-KRI limit linkage, so a KRI breach is an appetite breach.
- Automatic breach detection writing to measure_breaches, with a breach workflow
  escalating to the board risk committee.
- Effective-dated versions with approval. Inflation-indexed formula thresholds
  from WP-04.

TASK 6 — RISK ACCEPTANCE AND EMERGING RISK
- Risk acceptance as a formal, time-boxed object: rationale, compensating
  controls, accepted-by, expiry date, mandatory re-review, and auto-reopen on
  expiry. Today treatment_strategy can be 'accept' with no governance at all.
- An emerging-risk register distinct from the principal register, with velocity
  and proximity scoring and a promotion path into the main register.

TASK 7 — RISK VELOCITY, PROXIMITY, PERSISTENCE
risks.risk_velocity exists; add proximity (time to impact) and persistence
(duration of impact), and surface all three in the register and on the bubble
chart.

ACCEPTANCE
□ A risk has structured causes and consequences with barriers
□ An opportunity can be registered, assessed and plotted on its own heat map
□ Target risk plots on the matrix alongside inherent and residual
□ A risk carries a Basel event type AND an internal category simultaneously
□ Group appetite cascades to BU limits with a sum check
□ A KRI breach raises an appetite breach and escalates
□ An expired risk acceptance auto-reopens the risk
□ RiskAppetiteService has no reference to a non-existent column
```

---

## WP-11 — Assessment & Analysis Uplift

**Depends on:** WP-06, WP-10 · **Estimate:** 4 weeks

```
GOAL
Bring RCSA, the questionnaire UI and the analysis techniques to best practice.

TASK 1 — RCSA FULL LIFECYCLE
(WP-02 fixed the discarding stub; this builds the real thing.)
Scope → distribute → respond → SECOND-LINE CHALLENGE → sign-off → close.
- Prior-period pre-population with change highlighting ("last quarter you rated
  this 3×4; nothing has changed — confirm or revise").
- Second-line challenge workflow with rationale capture and override tracking.
- Assessment quality analytics: outlier detection (this BU rated a shared risk
  materially differently from peers), boilerplate/copy-paste detection,
  completion-time anomalies.
- Offline response capture (depends on WP-13 for the sync layer; build the data
  contract now).

TASK 2 — QUESTIONNAIRE BUILDER UI
The schema is ahead of the market (8 question types incl. matrix and file
upload, section and question weights, scoring_rules json, conditional_logic
json, 4 scoring methods, a global question library with usage counts) but
show.blade.php is 915 bytes.
- Build the visual builder the schema deserves: drag-and-drop sections and
  questions, a conditional-logic rule editor, a scoring-rule editor, weights,
  live preview, versioning, and publish.
- Verify and test that scoring_method, section weight, question weight,
  scoring_rules and conditional_logic are ACTUALLY consumed by
  CampaignController::submitResponse — the audit could not confirm this.
- Add a vendor/external respondent portal (feeds WP-22).

TASK 3 — EDITABLE BOW-TIE
Today AnalysisController::buildCauses()/buildConsequences() synthesise nodes
from risk fields — it is read-only and derived.
- Build a real bow-tie editor on the WP-10 cause/consequence model: threats →
  preventive barriers → top event → mitigative barriers → consequences, plus
  ESCALATION FACTORS and escalation-factor controls.
- Barrier effectiveness feeds residual risk.
- Export to PDF for the board pack.

TASK 4 — FMEA / FMECA
Failure mode, effect, cause; Severity × Occurrence × Detection; RPN; and the
AIAG-VDA Action Priority (High/Medium/Low) table. Link failure modes to
controls and actions. Templates per process type.

TASK 5 — HAZOP
Node / parameter / guide-word structure (No, More, Less, As well as, Part of,
Reverse, Other than), deviation, cause, consequence, safeguard, action.
Session records with participants and date. Relevant for energy and
manufacturing customers.

TASK 6 — FAULT TREE / EVENT TREE
Basic FTA with AND/OR gates and probability propagation; ETA with branch
probabilities. Both link to quantification scenarios (WP-18).

TASK 7 — CONFIGURABLE HEAT MAP WITH DRILL-THROUGH
Driven by the scoring profile (3×3 to 10×10), count badge per cell, click →
filtered register, period-over-period movement arrows, and an animate-across-
periods control.

TASK 8 — MATURITY ASSESSMENTS
Assess the organisation against framework principles: COSO ERM 2017 (5
components, 20 principles), COSO IC 2013 (17 principles), ISO 31000 clauses,
NIST CSF 2.0 tiers. Scored, evidenced, trended across periods, with a gap
report and improvement actions.

ACCEPTANCE
□ An RCSA campaign runs end to end including second-line challenge
□ Prior-period pre-population works with change highlighting
□ Outlier and boilerplate detection flag seeded test cases
□ The questionnaire builder produces a questionnaire whose conditional logic
  and weighted scoring demonstrably work at response time
□ A bow-tie with barriers and escalation factors can be built and exported
□ FMEA produces correct RPN and AIAG-VDA Action Priority
□ The heat map renders at 4×4, 5×5 and 6×6 with drill-through
□ A COSO ERM maturity assessment scores, trends and produces a gap report
```

---

## WP-12 — Reporting & Report Studio

**Depends on:** WP-08 · **Estimate:** 4 weeks

```
GOAL
WP-02 delivered PDF output and a board pack v1. This makes reporting a product.

TASK 1 — REPORT STUDIO
- report_templates(id, organization_id, code, name, output_formats JSON,
  page_setup JSON, sections JSON [{type, config, order}], parameters JSON,
  branding JSON, is_published, version)
- Section types: cover, table of contents, narrative (rich text with merge
  fields), widget (any WP-08 widget), register table, heat map, chart, signature
  block, appendix, page break.
- Merge fields resolve from the object graph and the measure engine at the
  selected period.
- Preview, then render to PDF / XLSX / DOCX / PPTX.

TASK 2 — SCHEDULED DISTRIBUTION
report_schedules(template_id, cron, period_binding, recipients JSON,
format, is_active). Queued generation, email delivery with the document
attached or a secure link, and a delivery log.

TASK 3 — BOARD PACK ASSEMBLY
Ordered, configurable sections; contributor workflow (each section owner
submits, the secretariat assembles); version control; approval; watermarking
for drafts; and a locked, reproducible final version tied to a closed period.

TASK 4 — REGULATORY PACKS
Template-driven generation for: CBN ICAAP submission, CBN CSAT (28 February),
CBN monthly CTI (by the 5th), NFIU STR/CTR, NDIC returns, SEC/NGX governance
report, NAICOM ORSA-equivalent, PenCom monthly high-risk extract (7 days after
month end), NDPC Compliance Audit Return (31 March), FRC NCCG compliance report
(31 March). Each with the correct deadline wired into the regulatory calendar
and an escalating reminder workflow.

TASK 5 — PERIOD-LOCKED REPRODUCIBILITY
A report generated for a closed period must regenerate byte-identically. Store
the parameter set and the period; refuse to regenerate a closed-period report
against changed data without an explicit "restate" action that is audited.

ACCEPTANCE
□ A non-engineer can build a report template in the Studio and publish it
□ A board pack generates as a branded, paginated PDF with a table of contents
□ A scheduled report is delivered by email on its cron
□ Every regulatory pack listed generates and its deadline appears in the calendar
□ Regenerating a closed-period report produces identical output
```

---

# PHASE 3 — THE AFRICA MOAT (weeks 21–32)

---

## WP-13 — Offline-First PWA & Mobile

**Depends on:** WP-09 · **Estimate:** 5 weeks · **The differentiator global vendors will not copy**

```
GOAL
Nigeria has ~190m mobile subscriptions but only ~110m broadband; the national
grid collapsed again in 2026 and ~70% of businesses run on generators. A
desktop-tethered, always-online web app assumes a working PC on mains power.
In most of the Nigerian corporate estate outside head office, that fails several
hours a day.

OFFLINE-FIRST IS THE ARCHITECTURE, NOT A FEATURE.

TASK 1 — PWA SHELL
- Web app manifest, installable, splash screens, app icons.
- Service worker with a versioned app shell, stale-while-revalidate for static
  assets, network-first with cache fallback for data.
- Explicit online/offline/syncing status in the UI at all times.

TASK 2 — LOCAL-FIRST DATA LAYER
- IndexedDB store (Dexie) holding: the user's assigned objects, their open
  workflow tasks, the risk register for their node subtree, control and KRI
  definitions, taxonomies, scales and reference data.
- Selective sync scoped by node subtree and role — never the whole tenant.
- Delta sync using updated_at cursors; compressed payloads; resumable.

TASK 3 — THE OUTBOX AND CONFLICT RESOLUTION
- Every offline write goes to a local outbox with a client-generated UUID.
- On reconnect, replay in order with idempotency keys (WP-07 supports these).
- Conflict policy: last-write-wins on scalar fields with a recorded loser;
  three-way merge with a REVIEW QUEUE on text fields and on any field where
  both sides changed. Never silently discard a user's work.
- A "pending sync" UI listing unsynced items with retry and discard.

TASK 4 — THE FIVE OFFLINE JOURNEYS
Scope offline WRITE support to exactly these (read-mostly offline elsewhere):
  1. Log a loss event or near miss (with camera photo and GPS)
  2. Complete an RCSA / campaign response
  3. Complete a control test with evidence
  4. Submit a KRI reading
  5. Approve / action a My Responsibilities task
Each must work end to end with the radio off.

TASK 5 — RESILIENCE TO POWER LOSS
- Autosave every field to IndexedDB on change (debounced), not on submit.
- Restore in-progress forms after an unplanned shutdown, with a visible
  "restored draft" banner.
- Resumable chunked uploads for attachments; a 12 MB photo on 2G must survive
  three disconnections.

TASK 6 — PERFORMANCE BUDGET FOR 2G
- Initial payload < 200 KB gzipped. First meaningful paint < 3 s on simulated
  2G (Chrome DevTools "Slow 3G" is too generous — use 2G, 300 ms RTT, 40%
  packet loss).
- List views render before charts. Charts lazy-load. Images compressed and
  optional. No blocking third-party requests (WP-00 removed the CDNs).
- Add a CI performance budget check that fails the build on regression.

TASK 7 — MOBILE LAYOUTS
Purpose-built mobile screens for the five journeys plus My Responsibilities.
Large tap targets, single-column, minimal typing, camera and GPS first.

ACCEPTANCE
□ A full RCSA completes offline on a mid-range Android and syncs without loss
□ A loss event with a photo and GPS is captured offline and syncs
□ Killing the browser mid-form loses nothing
□ Conflicting edits produce a review queue entry, never a silent overwrite
□ First meaningful paint < 3 s at 2G/300 ms RTT/40% loss
□ Initial payload < 200 KB gzipped
□ Lighthouse PWA score >= 90
```

---

## WP-14 — USSD & WhatsApp Intake

**Depends on:** WP-07 · **Estimate:** 3 weeks · **No global vendor does this**

```
GOAL
USSD works without data or a smartphone, which is why it remains structurally
important for African financial services. WhatsApp banking is launching actively
in Nigeria. A branch teller, agent or field officer must be able to report a loss
event or control failure by short code or WhatsApp message and have it land as a
structured record. This is the only way to get real event data out of a
40,000-agent network — and no global ERM vendor does it.

TASK 1 — CHANNEL ABSTRACTION
  intake_channels(id, organization_id, type ENUM(ussd|whatsapp|sms|email|web|api),
    config JSON, is_active)
  intake_sessions(id, channel_id, external_ref, msisdn_hash, user_id NULLABLE,
    state JSON, current_step, started_at, completed_at, resulting_object_id,
    abandoned_at)
  intake_messages(id, session_id, direction, body, media_ref, received_at)
Store msisdn HASHED (NDPA — phone numbers are personal data). Never log raw.

TASK 2 — CONVERSATIONAL FLOW ENGINE
- Flows defined as data (not code): steps with prompt, input type, validation,
  options, branching, and a mapping to target object fields.
- Ship flows for: report a loss event, report a near miss, report a control
  failure, submit a KRI reading, approve a pending task, check my tasks.
- Resumable sessions; timeout handling; a confirmation summary before submit;
  a reference number returned on success.

TASK 3 — USSD
- Integrate with a Nigerian aggregator (Africa's Talking, Termii, Infobip or a
  bank's own USSD gateway). Abstract behind a driver so the aggregator is
  swappable.
- Handle the 182-character screen limit, menu paging, and session timeouts.
- Identity: map MSISDN to a user; require a PIN for approvals.

TASK 4 — WHATSAPP
- WhatsApp Business API (Meta Cloud API or a BSP). Template messages for
  outbound, session messages for inbound.
- Support media (photo of a damaged ATM, a scanned memo) with automatic
  attachment to the created object.
- Rich interactive messages (list and button replies) where available.

TASK 5 — LANGUAGE
Local-language support belongs HERE, not in the platform UI. Support English
plus Hausa, Yoruba and Pidgin on the intake flows, driven by the flow
definition's per-language prompts.

TASK 6 — GOVERNANCE
- Every intake record is flagged with its channel and marked for triage —
  never auto-approved.
- Rate limiting and abuse protection per MSISDN.
- Full audit of the conversation attached to the resulting object.

ACCEPTANCE
□ Dialling the short code and completing the flow creates a real loss_event
□ A WhatsApp message with a photo creates a near miss with the photo attached
□ Sessions resume after a timeout
□ MSISDNs are hashed at rest; raw numbers appear in no log
□ Hausa and Yoruba flows work
□ Every intake record enters a triage queue
```

---

## WP-15 — Currency, FX & Inflation Indexing

**Depends on:** WP-04 · **Estimate:** 2 weeks

```
GOAL
Today loss_events.currency (char(3), default NGN) is the ONLY currency field in
the schema and it is used by no calculation. treatment_plans.cost_estimate_ngn
and risks.financial_exposure_ngn bake NGN into the column names. All
quantification is in kobo. There is no FX table and no reporting-currency
conversion.

Nigerian inflation was 15.91% in June 2026 (down from 33%+ peaks in 2024). The
CBN rate was ~₦1,365.69/USD on 7 August 2026 with a parallel spread. An appetite
statement reading "losses above ₦50m are High" decays meaningfully within 18
months. THIS IS WHY NIGERIAN RISK REGISTERS DRIFT INTO "EVERYTHING IS HIGH".

TASK 1 — CURRENCY EVERYWHERE
- Add currency_code + amount_minor pairs wherever money exists. Deprecate the
  *_ngn column names via the WP-01 two-migration pattern.
- A Money value object (amount minor units + currency) used throughout; no bare
  integers crossing a service boundary.
- Money input component that is currency-aware and minor-unit safe.

TASK 2 — FX RATES (built in WP-04, wired here)
- Rate types: cbn_official, nafem, parallel, internal, custom. The organization
  picks a default; a user can override per transaction with a recorded reason.
- Scheduled fetcher for the CBN official rate with manual entry fallback and
  full audit. Never silently interpolate a missing rate — fail loudly.
- Every converted value records fx_rate_used AND rate_type. A report must be
  able to state which rate produced it.

TASK 3 — REPORTING CURRENCY AND GROUP CONSOLIDATION
- Per-entity local currency; a group reporting currency.
- Roll-ups convert at the period-end rate and record it.
- A group risk report in USD reconciles to the NGN subsidiary reports at the
  stated rate.

TASK 4 — INFLATION-INDEXED THRESHOLDS (the differentiator)
- Threshold formulas from WP-04, with these named functions:
  @cpi_index(base_period)  — CPI ratio from a stored index series
  @capital(component)      — from icaap_assessments
  @revenue(period)         — from a registered financial measure
  @fx(from,to,date,type)
- cpi_series(id, organization_id NULLABLE, country_code, period_id, index_value,
  source, captured_at) — seed Nigeria CPI from NBS.
- At period close, re-evaluate every formula threshold. Where the drift exceeds
  a configurable tolerance, raise a re-baselining approval task showing the old
  band, the new computed band, the driver (CPI +x%, capital +y%) and the risks
  whose ratings would change. On approval, write a new effective-dated band.
- Produce a "rating drift report": how many risks would change rating under the
  new bands. This is the conversation a Nigerian CRO needs to have with the
  board once a year, and no product currently supports it.

ACCEPTANCE
□ A loss event in USD, a treatment cost in GBP and a risk exposure in NGN all
  roll up correctly into an NGN group report at recorded rates
□ Switching the org default rate type from cbn_official to nafem changes
  reported group totals and the report states which rate was used
□ A threshold defined as "0.5% of qualifying capital" re-evaluates at period
  close and raises an approval task
□ The rating drift report correctly lists risks whose band would change
□ No new code writes to a *_ngn column
```

---

## WP-16 — Content Pack Framework + NG-CORE + NG-BANK

**Depends on:** WP-05 · **Estimate:** 4 weeks · **The moat, productised**

```
GOAL
Ship starter content, not an empty tool. Risk maturity — not software features —
is the constraint in this market, and an empty tool is the number-one reason
global products get bought and abandoned. Regulatory content is also the moat:
global vendors cannot economically maintain Nigerian regulatory content.

TASK 1 — PACK FRAMEWORK
  content_packs(id, code, name, publisher, version, jurisdiction, sector,
    description, requires_platform_version, checksum, installed_at,
    installed_by, is_managed [auto-update], timestamps)
  content_pack_items(id, pack_id, item_type, code, payload JSON,
    depends_on JSON, timestamps)
  content_pack_bindings(id, pack_item_id, organization_id, local_object_id,
    binding_state ENUM(linked|customised|detached), customisation_diff JSON,
    timestamps)

item_type covers: object_type, attribute, taxonomy_node, obligation, control,
kri/measure, threshold_formula, risk_template, questionnaire, workflow,
dashboard, widget, report_template, calendar_entry, scale, scoring_profile,
framework_mapping.

- Install: creates local objects and records bindings.
- Upgrade: THE HARD PART. For each item, compare the new payload against the
  binding. If binding_state = linked → update in place. If customised →
  three-way merge and surface conflicts in a review queue. If detached → skip.
  CUSTOMER CUSTOMISATION MUST SURVIVE A PACK UPGRADE.
- Uninstall: archive, never hard-delete customer data derived from the pack.
- Artisan: pack:install, pack:upgrade --dry-run, pack:diff, pack:list.

TASK 2 — NG-CORE
- Nigerian risk taxonomy including the categories no global product models:
  insecurity / banditry / kidnapping (WITH location and route dimensions),
  power & diesel supply, FX & repatriation (official vs parallel), regulatory
  volatility, third-party agent networks, cash handling & cash-in-transit,
  host community & herder/farmer conflict, fuel supply, channel-level fraud
  typologies per NIBSS categories.
- NGN currency, CBN official / NAFEM / parallel rate types, Nigerian public
  holidays, NGN number and date formats, NG address model.
- Nigerian org-node types: Head Office, Zone, Region, Area, Branch, Cash Centre,
  Agent, Agent Aggregator.

TASK 3 — NG-BANK (the flagship)
- CBN prudential obligation register at clause level.
- ICAAP as a first-class OBJECT (not a report): risk appetite framework,
  per-risk-type Pillar 2 assessment (concentration, IRRBB, business/strategic,
  reputational, country/transfer, model, environmental & social), stress
  scenarios with management actions, internal audit findings, board resolution
  capture — with an END-OF-APRIL annual deadline workflow and a version and
  attestation trail. Wire it to the existing icaap_assessments model.
- Basel L1/L2/L3 event tree (all 7 Level-1 types with the full Level-2/3 tree)
  and the 8 business lines. Map to the existing loss_events.basel_l1/l2/l3
  columns.
- CBN ORMS mapping (existing cbn_risk_category, cbn_orms_event_type,
  cbn_product_line columns).
- Cyber returns calendar: 24-hour incident notification to Director BSD with a
  PRE-FORMATTED notification document, 28 February annual CSAT submission,
  monthly CTI report due by the 5th, quarterly board pack. Escalating workflow,
  not a static calendar entry.
- AML/CFT: NFIU STR (24-hour) and CTR obligations with the existing threshold
  service; SCUML; sanctions screening hooks.
- The CBN-mandated ORGANISATION STRUCTURE as a seeded node template: CISO
  reporting to MD/CEO (explicitly NOT to CRO or Head of IT), separate Board Risk
  and Board Audit committees, ISSC chaired by MD/CEO meeting quarterly, CRO at
  >= AGM grade. Access control and report routing follow it.
- Returns calendar entries for FinA, eFASS and CRMS.
- Starter KRI library (~60 banking KRIs with definitions, formulas, frequencies
  and suggested threshold formulas expressed as % of capital or revenue).
- Starter control library mapped to CBN requirements AND to ISO 27001:2022
  Annex A AND to NIST CSF 2.0 — proving the common control model.
- Seeded dashboards: CRO dashboard, Board Risk Committee dashboard, Branch
  Manager dashboard, ICAAP workspace.

TASK 4 — GOVERNANCE OF CONTENT
- Named content owner per pack.
- A regulatory-change watch process producing pack versions, with a published
  refresh SLA (target: updated within 10 working days of a new instrument).
- A changelog per pack version surfaced in-product.

ACCEPTANCE
□ pack:install NG-CORE + NG-BANK on an empty tenant produces a usable ERM
  system with taxonomy, obligations, KRIs, controls, calendar and dashboards
□ A customer customises a pack-provided control; pack:upgrade preserves the
  customisation and reports the conflict
□ Time from empty tenant to first PDF board report < 5 working days
□ One control demonstrably satisfies a CBN requirement, an ISO 27001 control
  and a NIST CSF subcategory, tested once and reported three ways
□ The ICAAP object generates a submission pack and its April deadline escalates
```

---

## WP-17 — Content Packs: Sectors & Standards

**Depends on:** WP-16 · **Estimate:** 4 weeks

```
Build, in this priority order, using the WP-16 framework. Each pack = taxonomy +
obligation register + control library + KRI library + questionnaires + workflows
+ calendar entries + report templates + dashboards.

1. NG-DATA (highest urgency — SGF Circular 59805 created several hundred
   simultaneous public-sector buyers with personal liability on the accounting
   officer)
   NDPA 2023 + GAID 2025: 72-hour breach clock with a live countdown, Schedule 4
   DPIA as a native form, DPIA trigger detection by processing category,
   Compliance Audit Return with a 31 March deadline and DPCO workflow, DPO CPD
   tracker (40 hrs/yr), semi-annual management report generator, records of
   processing (ROPA), cross-border transfer register with legal basis.

2. NG-INSURANCE (~43 insurers recapitalised 31 July 2026; NAICOM risk-based
   capital next; NO INCUMBENT SOFTWARE)
   ORSA-equivalent workpack, capital adequacy on the NIIRA definition
   (admissible assets − liabilities − own shares, NOT paid-up capital),
   solvency monitoring, NAICOM returns calendar, insurance risk taxonomy.

3. NG-PENSION — PenCom RMF for PFAs/PFCs, the MONTHLY HIGH-RISK EXTRACT with a
   7-day post-month-end deadline (that specific cadence is a product feature,
   not a config option), investment limit KRIs.

4. NG-PUBLIC — SGF Circular 59805 kit for MDAs, BPP/NOCOPO procurement risk,
   FRC/NCCG compliance report (31 March), public-sector risk taxonomy.

5. NG-CAPMKT — SEC Nigeria + ISA 2025 obligations, NGX listing and governance
   rules, capital market operator risk framework.

6. NG-ENERGY — NUPRC/NMDPRA obligations, HCDT 3% opex HOST-COMMUNITY MODULE
   with grievance logging, incident tracking by host community and stakeholder
   engagement records (global products have "stakeholder management"; none model
   a Nigerian host community trust), NERC federal AND state, community and
   security incident taxonomy.

7. NG-TELCO — NCC obligations, licence conditions, QoS KPIs as measures.

8. STANDARDS PACKS — framework structures, requirement trees, control libraries,
   maturity rubrics and cross-mappings for:
   STD-ISO31000, STD-COSO-ERM (5 components / 20 principles),
   STD-COSO-IC (17 principles), STD-ISO27001-2022 (93 Annex A controls,
   4 themes), STD-ISO27005, STD-ISO22301, STD-ISO37301, STD-ISO37001,
   STD-ISO42001, STD-NIST-CSF2 (6 functions incl. Govern), STD-NIST-800-53,
   STD-BASEL-OR, STD-SOLVENCY2, STD-COBIT2019, STD-IFRS-S1-S2, STD-TCFD,
   STD-GRI, STD-DORA, STD-PCI-DSS, STD-SOC2.
   Every standards pack must cross-map to at least one other so the common
   control model is demonstrable out of the box.

9. GH-CORE — Bank of Ghana Act 930, the climate directive effective January
   2026, cyber, outsourcing, NPL directives, Ghana Data Protection Act.

10. KE-CORE — CBK Risk Management Guidelines, Kenya Data Protection Act 2019
    (ODPC).

11. ZA-CORE — KING V DISCLOSURE FRAMEWORK built as a structured object:
    disclosure statements mapped to each RECOMMENDED PRACTICE (not merely each
    principle) across all 13 principles. Products still modelling King IV's 17
    principles will look visibly stale to South African boards from FY2026.
    Plus Prudential Authority and POPIA.

ACCEPTANCE
□ Each pack installs cleanly and independently
□ Each pack's calendar entries generate escalating reminder workflows
□ Cross-mapping demonstrable: one control, three frameworks
□ King V disclosure statements map to recommended practices, not just principles
```

---

# PHASE 4 — BEYOND PARITY (weeks 25–44)

---

## WP-18 — Quantification Level 5: FAIR, LEC, Distributions

**Depends on:** WP-07 · **Estimate:** 5 weeks · **Our clearest white space**

```
GOAL
We are at maturity level 4 (Monte Carlo on single risks) with ICAAP scaffolding
already built. Levels 5-6 inside a general ERM platform is the clearest white
space in the global market — most vendors bolt on a Monte Carlo widget and stop.

CURRENT STATE (read MonteCarloService before starting)
Frequency: Poisson ONLY (columns exist for binomial: frequency_n, frequency_p).
Severity: Lognormal ONLY (columns exist for location/scale/shape —
Pareto/GPD/Weibull-ready). VaR at 90/95/99/99.9 plus a 10-point percentile
distribution, persisted per scenario and as an aggregate row. Risk contributions
as simple % of expected loss. simulation_runs.correlation_method DEFAULTS TO
'GAUSSIAN_COPULA' IN THE SCHEMA BUT NO COPULA IS IMPLEMENTED — scenario losses
are summed independently per iteration (MonteCarloService line 77). Note
QuantificationController line 350 explicitly writes 'independent', so live rows
are honest; fix the misleading schema default in WP-01. stress_multiplier_frequency/_severity,
cbn_stress_scenario and stress_config are STORED AND NEVER APPLIED.
Runs SYNCHRONOUSLY in the HTTP request.

TASK 1 — ASYNC ENGINE (from WP-07)
Job-based with progress, cancel, seed control for reproducibility, and result
caching. Report iterations/second. Target: 100,000 iterations × 50 scenarios
under 60 seconds.

TASK 2 — DISTRIBUTION LIBRARY
Implement, with parameter validation and sampling:
  Frequency: Poisson, Negative Binomial, Binomial, Empirical
  Severity:  Lognormal, Normal, Uniform, Triangular, PERT/BetaPERT,
             Weibull, Pareto, Generalised Pareto (GPD), Gamma, Empirical
PERT is essential — it is the distribution risk practitioners can actually
parameterise from min/most-likely/max.

TASK 3 — DISTRIBUTION FITTING
Fit a distribution to historical loss_events data (maximum likelihood), report
goodness-of-fit (Kolmogorov–Smirnov, Anderson–Darling, AIC/BIC), and RECOMMEND
a distribution with a plain-English explanation. Warn when the sample is too
small to fit — do not fit silently on n=4.

TASK 4 — FAIR DECOMPOSITION
Implement the FAIR ontology as a scenario type:
  Loss Event Frequency = Threat Event Frequency × Vulnerability
  Threat Event Frequency = Contact Frequency × Probability of Action
  Vulnerability = f(Threat Capability, Resistance Strength)
  Loss Magnitude = Primary Loss + Secondary Risk
  Secondary Risk = Secondary Loss Event Frequency × Secondary Loss Magnitude
  Six loss forms: Productivity, Response, Replacement, Fines & Judgements,
  Competitive Advantage, Reputation
Each factor takes a PERT distribution (min/most likely/max/confidence). Provide
a guided estimation workflow with calibration prompts.

TASK 5 — LOSS EXCEEDANCE CURVES
Make the LEC the PRIMARY BOARD VISUAL, not the heat map: x = annual loss (₦),
y = probability of exceeding. Overlay: current risk, post-treatment risk, and
the risk appetite line ("we accept no more than a 5% chance of exceeding ₦2bn").
Where the curve crosses the appetite line is the conversation.
Add the lec_curve widget type to WP-08.

TASK 6 — CVaR AND TAIL METRICS
Add CVaR / Expected Shortfall at configurable confidence levels alongside the
existing VaR. Report both — VaR alone understates tail risk and any competent
bank risk officer will ask.

TASK 7 — SENSITIVITY / TORNADO
Identify which input uncertainties drive output variance (rank correlation or
regression on simulation output). Render as a tornado chart. Also drive the
Corporater "EBITDA / FCF / NPV per year" tornado bars — project simulated loss
onto financial-statement KPIs, which is what makes quantification legible to a
CFO.

ACCEPTANCE
□ 100k iterations × 50 scenarios in under 60 s as a background job with progress
□ All listed distributions sample correctly (statistical tests on moments)
□ Fitting a distribution to 200 seeded loss events recommends the right family
□ A FAIR scenario computes ALE and produces a loss exceedance curve
□ The LEC renders with the appetite line and the crossing point highlighted
□ CVaR is reported alongside VaR
□ The tornado chart correctly ranks input drivers
```

---

## WP-19 — Quantification Level 6: Portfolio, Capital, Backtesting

**Depends on:** WP-18 · **Estimate:** 5 weeks

```
TASK 1 — CORRELATION AND COPULAS
simulation_runs.correlation_method already DEFAULTS to GAUSSIAN_COPULA with no
implementation. Honour the column:
- Rank (Spearman) correlation matrix between scenarios, elicited or fitted.
- Gaussian copula and t-copula (t for tail dependence, which matters).
- Matrix validation (positive semi-definite) with nearest-PSD correction and a
  warning.
- Portfolio aggregation via the copula, NOT naive summation. Show the
  diversification benefit explicitly — the difference between the sum of
  standalone VaRs and the portfolio VaR is the number a CRO wants.

TASK 2 — LDA WITH EVT
Loss Distribution Approach: separate frequency and severity modelling, a
body/tail split with Peaks-Over-Threshold and a GPD tail, threshold selection
diagnostics (mean excess plot, Hill plot), and convolution to an aggregate
annual loss distribution. This is what a Nigerian bank's ICAAP Pillar 2
operational risk assessment actually needs.

TASK 3 — DATA COMBINATION
Combine the four Basel data elements: internal loss data, external loss data
(with scaling by size/revenue), scenario analysis, and BEICFs (business
environment and internal control factors — drive these from control
effectiveness and KRI levels). Document the weighting and make it auditable.

TASK 4 — STRESS AND REVERSE STRESS TESTING
stress_multiplier_frequency, stress_multiplier_severity, cbn_stress_scenario
and simulation_runs.stress_config are stored and NEVER APPLIED. Apply them.
- Named stress scenarios (CBN-prescribed and internal) applied as frequency and
  severity multipliers, with management actions modelled.
- REVERSE stress testing: solve for the scenario severity that breaches a
  capital or appetite threshold. "What would have to happen for our CAR to fall
  below 10%?" is the question the board asks.

TASK 5 — CONTROL ROI
For each control or treatment plan, compute the reduction in expected loss AND
in the 95th percentile, against the control's cost. Rank the control portfolio
by tail-loss reduction per naira. Output: "this ₦400m control reduces
95th-percentile annual loss by ₦3.1bn." That sentence sells the product.

TASK 6 — BACKTESTING
Compare quantified estimates against realised losses from loss_events by period.
Report calibration (are the 90th percentiles exceeded ~10% of the time?), bias,
and per-scenario accuracy. Feed the result back as a calibration prompt to the
estimator. Almost nobody does this, and it is what makes quantification credible
to a regulator.

TASK 7 — CAPITAL CALCULATION
- Basel SMA: Business Indicator → BI Component → Internal Loss Multiplier →
  Operational Risk Capital, with the CBN's national-discretion parameters.
- Wire the simulation output into icaap_assessments Pillar 2A and 2B — TODAY
  THOSE ARE INPUT FIELDS, NOT DERIVED FROM THE SIMULATION.
- Solvency II SCR module structure for NG-INSURANCE.
- Risk contribution / capital allocation to business units (Euler allocation),
  so the ICAAP can attribute capital to the units that generate the risk.

ACCEPTANCE
□ Portfolio VaR under a t-copula differs correctly from the naive sum, and the
  diversification benefit is displayed
□ POT/GPD tail fitting produces stable estimates with diagnostics
□ A CBN stress scenario applied to a base run produces a stressed capital number
□ Reverse stress testing solves for the CAR-breaching severity
□ Control ROI ranks the control portfolio by tail-loss reduction per naira
□ Backtesting reports calibration against seeded realised losses
□ icaap_assessments Pillar 2A/2B are DERIVED from simulation results
```

---

## WP-20 → WP-28 — Remaining Domain Modules

Each follows the same shape: a new object type set + relationships + workflows + dashboards + a content pack contribution, built on the platform core. Prompts are condensed; expand using the WP-16 pattern.

```
WP-20 — COMPLIANCE & OBLIGATIONS (4 weeks, depends WP-16)
Clause-level obligation register. The full linkage chain: obligation → policy →
control → risk → process → evidence. Multi-framework cross-mapping (a control
satisfying N requirements, tested once). Framework version migration (ISO 27001
2013→2022). Regulatory change feed with applicability filtering by entity,
jurisdiction and product. Examination management (requests, responses, findings,
commitments) — critical for CBN examinations, which the existing
issues.cbn_examination_finding fields already anticipate. Attestation cycles.
Retire regulatory_circulars into the obligation model.

WP-21 — POLICY MANAGEMENT (3 weeks, depends WP-06)
Repository with version control and exactly one published version. Authoring →
review → approval → publication → archival on the WP-06 engine. Hierarchy:
policy → standard → procedure → work instruction. ATTESTATION CAMPAIGNS with
targeted distribution by role/entity/geography and escalation. Policy-to-
obligation, policy-to-control and policy-to-risk mapping. Exception/waiver
workflow with expiry and compensating control. A mobile employee policy portal
with search.

WP-22 — THIRD-PARTY RISK (4 weeks, depends WP-06, WP-20)
Vendor master with lifecycle states. Criticality tiering with configurable
scoring. Tier-gated onboarding due diligence. Questionnaire library (SIG,
SIG Lite, CAIQ) with a VENDOR PORTAL (reuse the WP-11 questionnaire builder).
Evidence with certification expiry and auto-chasing. Contract repository with
key dates and clause/obligation extraction. Sanctions, PEP and adverse media
screening. Concentration risk by vendor, service, geography and cloud region.
Structured offboarding with data return/deletion attestation. Nth-party mapping.
NIGERIA-SPECIFIC: agent-network modelling — per-agent exclusivity, transaction
limits and principal liability under the October 2025 Agent Banking Guidelines,
across networks running to hundreds of thousands of points.

WP-23 — OPERATIONAL RESILIENCE & BCM (4 weeks, depends WP-03)
Important Business Service register with board-approved definitions. Impact
tolerances (time-based plus other metrics). Dependency mapping across people,
premises, technology, information and third parties — a natural fit for the
object graph. BIA with MTPD/MAO, RTO, RPO, MBCO. BC and DR plans with version
control. Crisis management structure and call trees. Exercise programme with
results, lessons learned and actions. SEVERE-BUT-PLAUSIBLE SCENARIO TESTING
against tolerance with pass/fail. Self-assessment document with board approval.
ISO 22301 clause-level evidence. Time-to-tolerance-breach computed by walking
the dependency graph.
NIGERIA-SPECIFIC: model power/diesel and connectivity as first-class
dependencies — they are the actual continuity risk here.

WP-24 — INTERNAL AUDIT & COMBINED ASSURANCE (4 weeks, depends WP-06)
Audit universe with risk-based scoring driven from the risk register.
Risk-based annual plan with capacity planning. Multi-year coverage analysis
against the register. Engagement management. RACM per engagement. Electronic
workpapers with cross-referencing, review notes and sign-off. Findings with
rating, root cause and management response — feeding the EXISTING issues module
rather than duplicating it. IIA Global Internal Audit Standards (2024)
conformance: 5 domains, 15 principles, 52 standards. QAIP records. Audit
committee pack. COMBINED ASSURANCE MAP across all three lines plus external
providers, with over-assurance and gap detection. Corporater states this
directly — "link risks to assurance frameworks across the THREE LINES OF
DEFENSE" — so the assurance map is a parity item, not a stretch goal.
RISK-FUNCTION RESOURCE ADEQUACY EVIDENCE: Corporater lists, as a compliance
benefit, evidencing "that your organization has sufficient resources (PEOPLE AND
TECHNOLOGY) available for proactive risk management, and that roles and
responsibilities are properly communicated and documented". Model it: headcount
and competency per risk function against an approved establishment, certification
and CPD tracking, systems inventory supporting the risk framework, budget vs
actual, and a generated adequacy statement for the board and the examiner.
Essentially nobody models this, and CBN and NAICOM both ask for it at
examination.

WP-25 — CONTINUOUS CONTROL MONITORING (4 weeks, depends WP-07)
Forrester says CCM is where the market is heading and it is immature everywhere
— including at Corporater. This is a window.
Control test scripting against source systems (not questionnaires): query the
core banking system for SOD violations, the IdP for orphaned accounts, the HRIS
for uncompleted training, cloud config for misconfigurations. Full-population
testing rather than sampling. Automatic exception → issue with owner and SLA.
CONTROL HEALTH AS A CONTINUOUS TIME SERIES (a measure, from WP-04) feeding
residual risk automatically — a degrading control moves residual risk without
human intervention. Automated evidence capture with immutable hashed provenance.
Scope deliberately: monitoring everything is a cost and noise trap.

WP-26 — ESG, CLIMATE & IFRS S1/S2 (4 weeks, depends WP-04, WP-16)
ESG metric library with unit conversion (reuse the measure engine). Data
collection workflow across entities with source evidence. DOUBLE MATERIALITY
assessment (impact and financial), stakeholder-inclusive. IFRS S1/S2 with the
Nigerian FRC roadmap staging built as a GUIDED THREE-STAGE WORKFLOW with the
exact artefacts: board resolution, gap analysis, implementation plan, policies,
transitional relief elections, materiality assessment, board training evidence,
scenario models, ERM framework, internal control documentation — with the FRC's
deadlines (3 months before FY start, 3 months after, 6 months after). NOBODY
ELSE HAS BUILT THIS. TCFD. CLIMATE AS A NATIVE RISK CATEGORY, not an ESG
bolt-on: physical and transition risk taxonomy, scenario analysis in both
quantitative and qualitative modes, Scope 1/2/3 GHG with the year-one Scope 3
relief flag, targets with performance tracking, and links from climate risks
into the main register. ASSURANCE-READY MODE: audit-grade lineage, evidence
attachment and immutability from day one, because Nigeria's assurance ladder
escalates to reasonable assurance by Year 7 under ISSA 5000.

WP-27 — STRATEGY & PERFORMANCE (3 weeks, depends WP-04)
This is the "P" in Corporater's GPRC and their stated differentiator.
Objectives hierarchy as objects. KPIs on the same measure engine as KRIs.
RISK-TO-OBJECTIVE MAPPING — "which strategic objectives are at risk" is the
question a board actually asks and almost no ERM tool answers. Initiative and
project portfolio. Balanced scorecard view. Strategic risk assessment linking
principal risks to strategic themes.

WP-28 — AI GOVERNANCE (3 weeks, depends WP-03)
The fastest-growing greenfield in GRC. AI system and agent inventory as objects.
EU AI Act use-case risk classification. ISO 42001 conformance. Bias, fairness
and drift monitoring as measures. Model cards. Human-oversight attestation.
King V requires an AI governance register explicitly, and Nigerian boards are
moving the same way.
CRITICAL: register OUR OWN agents (WP-29) as governed objects in this module.
"We govern our own agents with the same machinery we sell you" is a credible,
differentiated story — and it is true.
```

---

## WP-29 — Grounded AI

**Depends on:** WP-03, WP-07 · **Estimate:** 6 weeks · **Last, deliberately**

```
GOAL
An LLM over a typed graph with known relationships massively outperforms an LLM
over a document store. That is why this comes AFTER the object graph, not before.

CURRENT STATE
app/Services/LlmService.php is genuinely well-engineered: never throws (returns
''/[] plus lastError()), a real available() health check, JSON mode with
prose-extraction fallback, 24-hour response caching, cache-warming artisan
commands, driver=ollama, model=granite4:micro, on-prem — a credible data-
residency story. AiToolsController exposes 7 POST endpoints but per the repo's
own audit only the Risk Statement Builder is UI-wired.

TASK 1 — WIRE WHAT ALREADY EXISTS
Surface the existing endpoints in the UI: control recommendations, KRI
suggestions, control description, treatment description, KRI description,
executive narrative. Each with a visible "AI-suggested — review before saving"
state and an accept/edit/reject action that is logged.

TASK 2 — DOCUMENT → RISK EXTRACTION
Ingest board minutes, audit reports, incident narratives, regulatory
correspondence, contracts and strategy documents. Extract candidate risks with
suggested taxonomy classification, owner and links to existing register entries.
THE DIFFERENTIATOR IS NOT EXTRACTION — IT IS CONFIDENT DE-DUPLICATION AND
LINKING INTO THE EXISTING GRAPH. Every candidate lands in a review queue with
provenance (document, page, quoted span) and a confidence score. Nothing is
auto-created.

TASK 3 — AI-ASSISTED RCSA
This attacks the single biggest quality problem in ERM — assessment fatigue and
anchoring — and almost nobody does it well.
- Pre-populate from prior period + incident history + control test results +
  KRI trends.
- Suggest risk descriptions in the organisation's house style.
- FLAG INCONSISTENT SCORING ACROSS PEER UNITS ("your BU rated this Low while 8
  comparable units rated it High").
- Detect boilerplate and copy-paste responses.
- Summarise second-line challenge points for the reviewer.

TASK 4 — CONTROL-TO-FRAMEWORK AUTO-MAPPING
Given a new framework, auto-map its requirements to the existing control
library, flag partial coverage and gaps, and propose new controls.
Bidirectional, confidence-scored, with a human review queue and full provenance
for every proposed mapping. This is what makes the standards packs (WP-17)
compound.

TASK 5 — NATURAL-LANGUAGE QUERY OVER THE GRAPH
"Show me every risk above appetite that touches a process supporting a service
delivered by a tier-1 vendor" resolves against the object graph — WITH
PERMISSION ENFORCEMENT AND A CITATION TRAIL (every answer lists the object ids
it drew on). Also NL-to-dashboard: "show third-party concentration by region as
a treemap" generates a widget definition.

TASK 6 — GOVERNED AGENTS
Realistic, high-value agents: evidence collector (fetches artefacts on a control
test schedule), questionnaire chaser (pursues non-responders with escalating
tone), issue triager, control tester (executes a defined analytic and writes the
result), regulatory watcher, assessment pre-filler.
EVERY AGENT MUST HAVE: a declared scope, an approval threshold above which it
must ask a human, a full action log, a kill switch, and a registration as a
governed object in the WP-28 AI governance module.

TASK 7 — EMERGING RISK HORIZON SCANNING
Replace the WP-02 manual emerging-risk register with real ingestion: news,
regulatory pipelines, geopolitical feeds, peer disclosures. Cluster into themes,
assess relevance to THIS organisation's strategy and graph, propose watch-list
entries with velocity and proximity scoring. The leading edge is connecting the
external signal to the SPECIFIC internal objective or process it threatens.

TASK 8 — ANOMALY DETECTION ON MEASURES
Beyond static thresholds: seasonality-aware baselines, multivariate anomaly
detection, changepoint detection, and leading-indicator discovery (WHICH KRIs
ACTUALLY PREDICTED PAST LOSS EVENTS — computable now that both live in the
measure engine and loss events carry dates).

GOVERNANCE RULES FOR EVERY AI FEATURE
- Provenance and confidence displayed on every output
- A human review path; nothing auto-commits to the register
- Full prompt/response logging with retention policy
- On-prem model by default (Ollama) — the data-residency story only holds if
  nothing leaves the perimeter
- No invented metrics, ever (the WP-02 rule is permanent)

ACCEPTANCE
□ All 7 existing LLM endpoints are UI-wired with accept/edit/reject logging
□ Document extraction proposes candidate risks with provenance, and correctly
  de-duplicates against 3 seeded near-identical existing risks
□ AI-assisted RCSA flags a seeded peer-inconsistent score
□ Control auto-mapping maps a seeded control library to a new framework with
  confidence scores and a review queue
□ NL query returns permission-correct results with object-id citations
□ Every agent is registered in the AI governance module with a working kill switch
□ Nothing leaves the perimeter — verify with a packet capture
```

---

## WP-30 — Deployment Tiers & Data Residency

**Depends on:** WP-00 · **Estimate:** 3 weeks · **START THE PARTNER CONVERSATION IN MONTH 1**

```
GOAL
CBN Circular PSS/DIR/PUB/CIR/001/004 (15 June 2026) requires all Nigerian
payment transaction data to be stored and managed in Nigeria by 1 JANUARY 2027,
reinforced by NITDA's National Cloud Policy 2025 for financial, health and
government data. From that date this is an EXCLUSION CRITERION for every
payments-adjacent buyer — and most global SaaS competitors will not have a
Nigeria-resident tier in time. This is a competitive weapon, not just a
compliance obligation.

TASK 1 — THREE SELLABLE TIERS
  (a) Nigeria-region cloud — a Nigerian data centre or Nigerian-partner cloud
  (b) Customer private cloud — deployed into the customer's own tenancy
  (c) Genuine on-premise — an installer, not a "we can do it on request"
Each with documented architecture, sizing, HA/DR, backup and RTO/RPO.

TASK 2 — HARDENED DEPLOYMENT ARTEFACTS
- Docker Compose and Kubernetes manifests. A one-command installer replacing the
  current project-root install.php (deleted in WP-00).
- Zero external runtime dependencies (WP-00 removed the CDNs — verify with a
  packet capture and publish the result; the on-prem claim is not credible
  otherwise).
- Air-gapped install path: bundled assets, bundled Ollama model, offline
  composer/npm caches.
- Automated backup, restore, and a documented, TESTED DR runbook with a stated
  RTO. Corporater publishes a 24-hour RTO; match or beat it.

TASK 3 — RESIDENCY ATTESTATION
A publishable document a bank's CISO can accept: where data lives, where it
transits, where backups live, which sub-processors exist (ideally none for
on-prem), encryption at rest and in transit, key management, and how the
NDPA/GAID cross-border rules are satisfied.

TASK 4 — MULTI-REGION FOR PAN-AFRICAN GROUPS
Data localisation and AfCFTA pull in opposite directions. Architect for
PER-COUNTRY data residency with a SHARED CONTROL AND OBLIGATION CATALOGUE
REPLICATED (not centralised) — so a pan-African group runs one framework with
data physically segregated per jurisdiction, and group reporting aggregates
metrics without moving underlying records.

TASK 5 — COMMERCIAL PACKAGING
Naira pricing, locally invoiced, local payment rails. FLAT OR ENTITY-BASED
TIERS, NEVER PER-SEAT — per-seat pricing structurally kills the front-line data
capture (WP-13, WP-14) that makes the product valuable. Reference point: Pirani
at $276/month flat. Target mid-market band: ₦4m–₦30m/yr equivalent. Price all
three deployment tiers. Produce BPP/NOCOPO tender-ready documentation.

ACCEPTANCE
□ All three tiers deploy from documented artefacts and pass a smoke test
□ A packet capture on the on-prem install shows zero outbound connections
□ The residency attestation document exists and has been reviewed by counsel
□ A DR restore is executed and timed against the stated RTO
□ Pricing is published in naira for all three tiers
```

---

# Appendix A — Definition of Done (every WP)

```
□ Feature works and is covered by tests (unit for calculations, feature for flows)
□ Tenancy enforced — cross-tenant test passes
□ Authorization enforced — every new route guarded, test passes
□ No mt_rand / rand / invented numbers in anything user-facing
□ Migrations are additive; no column dropped in the release that stops writing it
□ Money carries a currency; conversions record the rate and rate type
□ Strings are translatable; no hardcoded ₦ or English in Blade
□ New UI meets WCAG 2.2 AA; axe-core clean
□ Performance: no N+1 (assert with a query-count test); 2G budget respected for
  anything on the first-line path
□ Documented: user-facing in the in-app help, technical in docs/
□ ./vendor/bin/pint --test passes; full suite green; CI green
□ Demoable in under 5 minutes with seeded data
```

# Appendix B — Suggested branch and PR convention

```
Branch:  wp-<nn>/<short-slug>            e.g. wp-03/object-graph-core
PR title: WP-<nn> — <Title>
PR body must contain:
  - What changed
  - What deliberately did NOT change
  - Migration notes (additive? backfill? follow-up drop ticket?)
  - Acceptance criteria checklist from this pack, ticked
  - Screenshots for anything user-facing
  - Rollback plan
```

# Appendix C — Order of execution, at a glance

```
Weeks  1–4   WP-00 ║ WP-01 ║ WP-02          (parallel, all blockers)
Weeks  5–9   WP-03 → WP-04
Weeks  8–12  WP-05 ║ WP-06 ║ WP-07          (parallel after WP-03)
Weeks 10–16  WP-08 → WP-09
Weeks 13–20  WP-10 → WP-11
Weeks 17–24  WP-12 ║ WP-16
Weeks 21–28  WP-13 ║ WP-14 ║ WP-15
Weeks 25–32  WP-17 ║ WP-18
Weeks 29–36  WP-19 ║ WP-20 ║ WP-21 ║ WP-22
Weeks 33–40  WP-23 ║ WP-24 ║ WP-25 ║ WP-26
Weeks 37–44  WP-27 ║ WP-28 ║ WP-29
Month 1      WP-30 partner conversation starts; build weeks 30–33

13-WEEK DEMO CUT (if a deal forces it):
  WP-00, WP-01, WP-02 → WP-03, WP-04 (scoped) → WP-08, WP-12 → WP-16, WP-15
```
