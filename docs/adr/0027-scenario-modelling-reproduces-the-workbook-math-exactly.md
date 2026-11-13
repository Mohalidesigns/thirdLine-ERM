# ADR 0027 — Scenario Modelling reproduces the LS workbook's math exactly, and the deck's tiers are rows, not documents

**Status:** Accepted · **Date:** 2026-11-10 · **Phase:** Scenario Modelling & Analysis, Phase 0 → Phase 1 (`plans/scenario-modelling/SCENARIO-MODELLING-PLAN.md` v1.0, approved 2026-11-10) · **Author:** architect
**Amended:** 2026-11-10, Amendment 1 (end of file) — the Phase 1 column-level schema freeze the body's last consequence called for, reconciled against `plans/scenario-modelling/VOCABULARY-EXTRACTION.md`. Accepted at Phase 1 kickoff, as plan §5 anticipated. Where an amendment item and the body disagree, the amendment wins.
**Requested by:** Mohammed, 2026-11-10 — "Scenario Modelling and Analysis to enable the assessment of potential operational risk scenarios", benchmarked against global practice (`plans/scenario-modelling/GLOBAL-BENCHMARK-RESEARCH.md`).
**Consumers:** backend-engineer (models, migrations, `ScenarioComputationService`) · ui-designer · frontend-engineer · qa-engineer (golden-value fixtures) · code-reviewer · Quantification module (bridge consumer, §D5) · RiskTaxonomy seeder (§D3)

## Context

The module's two reference artefacts are an AXA-style Local Scenario workbook and a bank
Ebola scenario deck. The workbook is not a sketch: it is a working quantification instrument
whose published totals a customer's risk team has already signed off. Phase 0 decoded its
formulas from the BIFF stream and reproduced **all twelve published figures bit-for-bit**
(`plans/scenario-modelling/PHASE0-SPIKE.md`). The expensive-to-answer-twice questions are:
where the module lives, what the computation contract is, how the deck half is stored, and
how a signed-off scenario reaches capital numbers.

## Decisions

**D1 — The workbook is the specification, not an inspiration.**
`App\Services\Sm\ScenarioComputationService` reproduces the decoded formulas exactly —
including the ones that look odd: the hiring-cost impact multiplied by the frequency
interval `1/λ` (spike §3), the residual-amount first branch returning `t` below the
deductible (§4), and the non-linear level-score table `{1: 0.20, 2: 0.40, 3: 0.55,
4: 0.70, 5: 0.90}` (§1). Where the workbook's semantics are ambiguous, the observed
behaviour wins and a comment names the ambiguity. Golden-value characterisation tests pin
the twelve published figures; a refactor that moves one of them is a defect, not a rounding.

**D2 — Driver aggregation is typed, not free-form.**
Frequency drivers and impact criteria rows carry a computation *role* (count, probability,
control-score-complement, unit-amount, duration, flat-fee, subtotal) and impact types carry
an aggregation *template*. The service ships the two workbook templates
(`count × amount × duration × interval` and `(count × amount + flat) × probability`);
tenant-defined templates are out of scope for v1. Every driver row keeps its **data source**
(HR information / scorecard / expert judgement / internal loss data / external data) — the
provenance rule this product already enforces for the quantification library applies to
every scenario number.

**D3 — OP1–OP17 and COSO live in `RiskTaxonomy`, the scales live in config.**
The Basel event-type list is seeded as taxonomy nodes under framework `Basel III` (tenant-
extendable toward CBN ORMS sub-types). The reputational and legal/compliance 6-point scales,
severity labels (Typical/Serious/Extreme — fixed size, configurable labels), the cause
checklist, and the data-source vocabulary are module configuration, not taxonomy nodes:
they are vlists, not hierarchies.

**D4 — The deck half is rows in the same aggregate, behind the same lifecycle.**
Escalating variants (Ebola S1/S2/S3), their triggers/impacts/responses/owners, gap actions
and immediate-plan items are `sm_scenario_variants` / `sm_scenario_actions` children of the
one `sm_scenarios` root — not a separate document type. One scenario theme = one aggregate =
one sign-off lifecycle (`draft → in_design → designed → in_validation → validated →
signed_off → archived`, plus `returned_for_rework`). Sign-off freezes; post-sign-off edits
require revalidation. A typed notes log (assumption / dissent / challenge / workshop_minute)
plus an `is_independent_challenge` sign-off flag carry the bias-mitigation trail the Fed
FEDS note and PSMOR expect; no per-participant elicitation app in v1.

**D5 — The quantification bridge fits percentile anchors, it does not copy parameters.**
On export, a signed-off scenario's expected annual frequency becomes Poisson λ and its three
net-impact severities become lognormal anchors: Typical = median, Serious = 95th percentile,
Extreme = 99th (defaults; tenant setting for insurers' 99.5%), σ = (ln(q) − µ)/z_p. The
export creates a `QuantificationScenario` (provenance stamped with the scenario reference
and sign-off date) that the existing simulation/ICAAP pipeline consumes unchanged. Nothing
in Quantification learns about `Sm`; the bridge is one service, one direction.

**D6 — Standard module wiring, no new mechanisms.**
Namespace `App\*\Sm`, tables `sm_*`, permissions `scenario.*` declared once in
`RiskPermissionCatalog`, Policy-per-model, Form Requests with tenant-bound `Rule::exists`,
`BelongsToOrganization` tenancy, feature flag `scenario_modelling` default-off behind
`feature:` middleware, route group prefix `scenarios`, `GridPresenter`/`FormSchemaPresenter`/
`Chart.js`/`DocumentRenderer` reuse. Event times are nullable `dateTime()`; date handling
follows the day-boundary rule (§14 of the development standard); SQL stays inside the
MariaDB 10.4 ∩ MySQL 8.0.46 intersection; any digest over a json column goes through
`CanonicalJson`.

## Consequences

- The computation service is testable without HTTP: the golden fixtures are pure
  input → output pairs, and both CI database legs exercise the same PHP math.
- Storing driver *roles* and impact *templates* as data means the Excel export/import
  (Phase 5) round-trips the workbook layout from the same structures the engine reads —
  one vocabulary, three surfaces (UI, engine, Excel).
- D1 locks us to the workbook's semantics even where a actuarial reviewer might prefer
  different ones (e.g. the interval factor inside impact). If a customer later needs the
  "clean" variant, it is a new template under D2, never a silent change to the pinned one.
- D5 keeps Quantification's schema untouched; the bridge's percentile defaults live in
  quantification settings, so insurer tenants retarget 99.5% without code.
- Phase 1 must re-confirm the column-level schema freeze against this ADR before writing
  migrations; the table list in the plan (§4.2) is the boundary of the freeze.

## Amendment 1 (2026-11-10) — Phase 1 column-level schema freeze

**Why.** The body froze the *table list* and the computation contract, not a column, and its last consequence says Phase 1 must. **A column not named here does not exist in Phase 1.** Every string is verbatim from `plans/scenario-modelling/VOCABULARY-EXTRACTION.md`; where the extraction and the plan's prose disagree, **the extraction wins** and the divergence is recorded. Every codebase claim was read, not inferred — the migrations for `risks`, `loss_events`, `business_units`, `business_processes`, `key_risk_indicators`, `fx_rates`, `risk_taxonomies`, `bcms_scenarios`, `bcms_management_reviews`; plus `RiskPermissionCatalog`, `MorphTypes`, `AppServiceProvider`, `config/features.php`, `routes/web.php`, `NavPresenter`, `AuditTrailService`, `BusinessProcessSeeder`, `BcmsReferenceSeeder`, `scripts/deploy.sh`, `PermissionCatalogCoversRoutesTest`, `ObjectIdentityTest`, ADRs 0007/0008/0014/0017/0022/0026.

**Authority, corrected before it is cited wrongly.** The brief attributed "no invented migration" to `DEVELOPMENT_STANDARD.md` §10; §10 is *"What the test suite does not cover"* and says nothing about migrations. The rule is **BCMS standing rule 2** (`docs/bcms/WORKING-AGREEMENT.md`, from `plans/bcms/BCMS-ORCHESTRATION.md` §8) — *no structural migration after the freeze without a numbered ADR*, the rule ADR 0008 satisfies. It is a BCMS working agreement, not a product standard entry, and **this amendment adopts it for `Sm`**.

### A1.1 Boundary: five tables, and one plan table refused

Phase 1 creates exactly `sm_scenarios`, `sm_scenario_causes`, `sm_business_line_allocations`, `sm_scenario_signoffs`, `sm_scenario_notes`.

**`sm_scenario_exposures` is REFUSED, not postponed.** Plan §4.2 listed it, then said "could be columns on `sm_scenarios` … leaning to columns, they are 1:1" — decided, columns (§A1.2 rows 21–25). A 1:1 child is a join with nothing on the far side: one more query per workspace load, policy and Form Request, for no field the parent lacks.

The other nine §4.2 tables (`sm_frequency_drivers`, `sm_impact_types`, `sm_impact_criteria`, `sm_insurance_coverages`, `sm_scorecards`, `sm_scorecard_components`, `sm_scenario_variants`, `sm_variant_sections`/`sm_variant_owners`, `sm_scenario_actions`) are **Phase 2–3 and are not frozen here**: §4.3's engine contract shapes those columns and Phase 1 has no evidence to shape them with. Amendment 1 alters no shipped table and touches neither `Bcms\Scenario`, `QuantificationScenario` nor `RiskCause` — all three examined for reuse (§A1.4, §A1.8, §A1.11), all three a different concept.

### A1.2 `sm_scenarios`

One row per scenario theme (D4). Aggregate root: `uuid` per ADR 0007 deviation 4, soft deletes, `BelongsToOrganization`.

| # | Column | Type | Null | Default |
|---|---|---|---|---|
| 1 | `id` | `id()` | no | auto |
| 2 | `uuid` | `uuid()`, unique | no | — set in a `creating` hook, as `QuantificationScenario::boot()` does |
| 3 | `organization_id` | `foreignId()->constrained()->cascadeOnDelete()` | no | — |
| 4 | `reference` | `string(40)` | no | — |
| 5 | `name` | `string(250)` | no | — |
| 6 | `nature` | `string(20)` | no | `'local'` |
| 7 | `status` | `string(24)` | no | `'draft'` |
| 8 | `risk_id` | `foreignId()->constrained('risks')->nullOnDelete()` | yes | NULL |
| 9 | `risk_taxonomy_node_id` | `foreignId()->constrained('risk_taxonomies')->nullOnDelete()` | yes | NULL |
| 10 | `business_unit_id` | `foreignId()->constrained('business_units')->nullOnDelete()` | yes | NULL |
| 11 | `weighting` | `string(20)` | yes | NULL |
| 12 | `currency` | `char(3)` | no | `'NGN'` |
| 13 | `fx_rate_id` | `foreignId()->constrained('fx_rates')->nullOnDelete()` | yes | NULL |
| 14 | `fx_rate` | `decimal(18,8)` | yes | NULL |
| 15 | `fx_rate_date` | `date` | yes | NULL |
| 16 | `designed_on` | `date` | yes | NULL |
| 17 | `validated_on` | `date` | yes | NULL |
| 18 | `review_due_on` | `date` | yes | NULL |
| 19 | `background` | `longText` | yes | NULL |
| 20 | `rationale` | `text` | yes | NULL |
| 21 | `reputational_exposure` | `string(20)` | yes | NULL |
| 22 | `legal_compliance_exposure` | `string(20)` | yes | NULL |
| 23 | `insurance_risk` | `boolean` | **yes** | NULL |
| 24 | `financial_risk` | `boolean` | **yes** | NULL |
| 25 | `eligible_to_transfer` | `boolean` | no | `false` |
| 26 | `created_by` | `foreignId()->constrained('users')->nullOnDelete()` | yes | NULL |
| 27 | `updated_by` | `foreignId()->constrained('users')->nullOnDelete()` | yes | NULL |
| 28 | `created_at`, `updated_at` | `timestamps()` | yes | NULL |
| 29 | `deleted_at` | `softDeletes()` | yes | NULL |

Casts: `fx_rate` `decimal:8`; the four day columns `date`; the three flags `boolean`. A nullable boolean under the `boolean` cast reads back `null`, not `false` — `boolean` is in `HasAttributes::$primitiveCastTypes` and `castAttribute()` returns null unchanged for those — so `?bool` is real and §5's "absent, not zero" survives the cast.

**Indexes** (names frozen, abbreviated for MySQL's 64-char limit): unique `sm_scenarios_org_reference_unique (organization_id, reference)`; then `sm_scenarios_org_status_idx (organization_id, status)` for the grid and pipeline tiles, `sm_scenarios_org_taxonomy_idx (organization_id, risk_taxonomy_node_id)` for the OP heatmap, `sm_scenarios_org_unit_idx (organization_id, business_unit_id)`, `sm_scenarios_org_review_due_idx (organization_id, review_due_on)` for the refresh prompt.

**Decisions, with what was refused.**

- **`reference` unique per tenant, not globally.** `loss_events.event_reference` is `->unique()` outright — a defect waiting for a second tenant. Followed instead: `bcms_management_reviews`, the nearest analogue (a governance record with a human reference), at `string(40)` + `unique(organization_id, reference)`, as `risks.risk_code` and `bcms_scenarios.code` also do. *Refused:* a global unique and a DB sequence — the service generates it and a collision is a retry.
- **`currency` `char(3)` default `'NGN'`** — the workbook's sign-off block reads "Local Currency (NGN)", and `fx_rates.from_currency`/`to_currency` are `char(3)`. *Refused:* NULL; every figure here is money and a currency-less scenario cannot be converted.
- **Three FX columns, not one.** `fx_rate_id` is provenance; `fx_rate` + `fx_rate_date` are the **snapshot** D1/§4.3 require, because `fx_rates` rows are per-tenant and a platform rate can be superseded — a signed-off figure must not move when the rate table does. Precision is exactly `fx_rates.rate`'s `decimal(18,8)`; narrower silently rounds a figure an examiner recomputes. `fx_rate_date` keeps its source column's name rather than the `_on` suffix so it reads against `fx_rates.rate_date`.
- **`designed_on`/`validated_on`/`review_due_on` are `date`, RENAMED from the plan** (`designed_at`, `validated_at`, `review_due_date`). The workbook records "Date of Design" and "Date of Validation" as days; §13 reserves `dateTime()` for an instant, and ADR 0026 settled the suffix that says so (`run_on`, `requested_on`, `due_on`, `sent_on`, `held_on`): `_on` is a Lagos calendar day, `_at` an instant. Beyond tidiness — §14's defect was a `date` read with `->isPast()`, and a column named `_at` invites exactly that. Readers compare `review_due_on` with `whereDate(..., '<', now()->toDateString())`.
- **`weighting` nullable, no default** — it is a judgement, and `->default('medium_low')` would be a fabricated figure on every scenario nobody has weighted (§5). `nature` *is* NOT NULL default `'local'`: a classification the designer knows at creation, where `local` is the narrowest claim — the shape of `risks.status` defaulting `'draft'`.
- **`insurance_risk`/`financial_risk` NULLABLE booleans** — the workbook dropdowns are Yes/No *and* offer a blank ("unset = NULL", extraction). `->default(false)` would render "not yet assessed" as "No" on a governance screen. `eligible_to_transfer` is NOT NULL default `false` because its dropdown is only `0`/`1` with no unset state, and because Phase 2's netting (`transferable = Σ type_total × eligible_flag`, spike §4) needs a value — NULL would be a third case in a two-case formula.
- **`background` `longText`, `rationale` `text`** — the split `bcms_scenarios` uses for `summary`/`narrative`; the workbook's background block is a page of prose.
- **No `approved_by`/`approved_at`/`signed_off_at`** — sign-off is rows (§A1.6): three sources, three sign-offs, one status. A denormalised timestamp is a second source of truth that drifts the first time a sign-off is returned and re-given.
- **`risk_id` nullable and singular.** *Refused for Phase 1:* an `sm_scenario_risks` pivot for plan §4.4's "maps to risk(s)" — one scenario is designed against one register entry in the workbook, `risks` already carries the unit and category, and a pivot with no screen is a table nobody can test. Many-to-many, if the Phase 4 risk-360 needs it, is an amendment naming Phase 4.

### A1.3 The owning unit is `business_unit_id`; `entity_id` is deferred

Row 10 points at **`business_units`**. `risks.business_unit_id` *and* `risks.process_id` (`2026_02_22_200006:23-24`) already separate "which unit" from "which process", and `loss_events`, `issues` and `kri_returns` all carry `business_unit_id`. ADR 0026 §1 exists because `key_risk_indicators` had `entity_id` and `OrganisationGraphUnifier::resolveNodeIds()` records that KRIs have "no business_unit_id and, **in practice, no entity_id either**", so the graph could not resolve a department; ADR 0026 decision 0.5 then settled "**`business_units` are authoritative**". `entities` is the *scoping* tree (`ScopingSeeder` types GROUP, SUBSIDIARY, DIVISION, DEPARTMENT, REGION, BRANCH, UNIT, PROCESS, SYSTEM) that `GraphScope` walks for "which part of the org chart is in scope" — a different question from "which unit owns this scenario". `business_units` rows are departments and divisions (`DemoDataSeeder`: BU-IT, BU-IA, BU-LG, BU-CX, BU-PB, BU-OP …), which is what the workbook's allocation block means by a business line.

*Refused:* `entity_id` — both would repeat the KRI defect one ADR later, a second org FK nothing writes beside the one that is authoritative. The workbook's "Entity" is answered by `nature` + `business_unit_id`. *Deferred to Phase 4* (§A1.13), not refused forever: if the CBN evidence pack or the bridge needs per-legal-entity aggregation, that phase names it and arrives with a screen that reads it.

### A1.4 `sm_scenario_causes`

**Sparse: one row per ticked cause**, not 23 pre-materialised rows — the checklist is a const (§A1.8), so absence means "not selected". *Refused:* full materialisation and the sync-on-save machinery it needs; unlike `kri_return_lines`, nothing here is derived from a due-set that changes under the user.

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | `id()` | no | auto |
| `organization_id` | `foreignId()->constrained()->cascadeOnDelete()` | no | — |
| `sm_scenario_id` | `foreignId()->constrained('sm_scenarios')->cascadeOnDelete()` | no | — |
| `side` | `string(10)` — `internal` \| `external` | no | — |
| `cause_group` | `string(20)` — `people` \| `process` \| `system` \| `external` | no | — |
| `code` | `string(50)` — one of the 23 frozen codes | no | — |
| `is_selected` | `boolean` | no | `true` |
| `note` | `text` | yes | NULL |
| `sort_order` | `unsignedSmallInteger` | no | `0` |
| `created_at`, `updated_at` | `timestamps()` | yes | NULL |

Unique `sm_causes_scenario_code_unique (sm_scenario_id, code)`; index `sm_causes_org_scenario_idx (organization_id, sm_scenario_id)`. No soft deletes — a child line table, per ADR 0026 §2; unticking deletes the row and `AuditTrailService` records it.

- `side` and `cause_group` are **derived from `code` by the service at insert, never accepted from the request** — a client posting them is how a row arrives `side = external` with `code = people_other`. **No `label` column**: the const map keyed by `code` is the authority, and a stored copy means a Phase 5 rename updates some rows and not others, so the Excel round-trip exports two spellings of one cause.
- `sort_order` freezes report/export row order so a tenant extending the checklist (Phase 5) cannot reshuffle a signed-off scenario's qualitative report; on-screen grouping order comes from the const. `is_selected` defaults `true` because rows are sparse, and exists so "considered and rejected, see note" is expressible — which ISO 31010's document-your-assumptions rule (D4) needs.
- **Not `RiskCause`**: that model belongs to a *risk* ("a cause outlives the quarter that found it … causes accumulate on the risk") and carries `risk_id`, `cause_category_id`, `source`, `is_primary`. Reuse was considered and refused — it would put a `risk_id` on a row with no risk, or make it nullable, and mix two vocabularies in the table `RiskCauseCategory::options()` renders.

### A1.5 `sm_business_line_allocations`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | `id()` | no | auto |
| `organization_id` | `foreignId()->constrained()->cascadeOnDelete()` | no | — |
| `sm_scenario_id` | `foreignId()->constrained('sm_scenarios')->cascadeOnDelete()` | no | — |
| `business_unit_id` | `foreignId()->constrained('business_units')->cascadeOnDelete()` | no | — |
| `percent` | `decimal(5,2)` | no | — |
| `created_at`, `updated_at` | `timestamps()` | yes | NULL |

Unique `sm_allocations_scenario_unit_unique (sm_scenario_id, business_unit_id)`; index `sm_allocations_org_unit_idx (organization_id, business_unit_id)` for "which scenarios touch this unit".

- **`business_units`, not `business_processes`.** §A1.3's evidence applies, plus three facts: the workbook's allocation rows are *business lines* (Property and Casualty / Life and Savings / Health / Investment / Pensions — insurer lines, so the FK must be to the tenant's own tree, never a fixed list); `business_processes` is a *process catalogue* consumed by RCSA (`RcsaRegisterRisk`, `RcsaAssessmentLine`) and mirrored again by BCMS's `bcms_processes`, so pointing allocations at it puts two org trees on one screen; and `risks` already models the pair as `business_unit_id` + `process_id` — the unit is the owning level, the process the refinement. *Deferred:* a nullable `business_process_id` if a tenant allocates below the unit.
- **`cascadeOnDelete`, not `nullOnDelete`**: a NOT NULL column cannot be nulled, and "40% to nobody" is worse than a missing row — the missing row is caught by the invariant below, the meaningless one is not. `decimal(5,2)` matches `risks.control_effectiveness_pct`.

**Sum-to-100 is a service invariant, not a DB check.** `grep` for `CHECK (` / `->check(` / `ADD CONSTRAINT … CHECK` across `database/migrations/*.php` returns **nothing** — no CHECK precedent on either engine, and one here would fail a save with `SQLSTATE[23000]` where the Form Request should have answered 422. So the allocation service compares `Σ percent` to `100.00` **as decimals at the column's precision** (string/`bccomp`, never float `==`) and answers **422 on key `allocations`**, enforced at the **`in_design → designed` transition** and not on every draft save — a draft legitimately sums to 60% mid-entry, and refusing to save it pushes users into one big form. Lifecycle rules stay in the controller (standard §3); a wrong-status attempt is a flash message, not a 403.

### A1.6 `sm_scenario_signoffs`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | `id()` | no | auto |
| `organization_id` | `foreignId()->constrained()->cascadeOnDelete()` | no | — |
| `sm_scenario_id` | `foreignId()->constrained('sm_scenarios')->cascadeOnDelete()` | no | — |
| `source` | `string(20)` — `source_1` \| `source_2` \| `source_3` | no | — |
| `user_id` | `foreignId()->constrained('users')->nullOnDelete()` | **yes** | NULL |
| `signatory_name` | `string(200)` — snapshot | no | — |
| `role` | `string(100)` — snapshot, free text | no | — |
| `decision` | `string(20)` — `approved` \| `returned_for_rework` | no | — |
| `is_independent_challenge` | `boolean` | no | `false` |
| `comment` | `text` | yes | NULL |
| `signed_at` | `dateTime` | **yes** | NULL |
| `created_at`, `updated_at` | `timestamps()` | yes | NULL |

Indexes `sm_signoffs_scenario_source_idx (sm_scenario_id, source, signed_at)` and `sm_signoffs_org_scenario_idx (organization_id, sm_scenario_id)`. No soft deletes, no `updated_by`: a sign-off is appended to, never edited.

- **NO unique key on `(sm_scenario_id, source)`, deliberately.** D4 says sign-off freezes and post-sign-off edits require revalidation — so source 1 signs, the scenario is returned, and source 1 signs *again*; a unique key would force an UPDATE over the record this table exists to protect. **The current sign-off for a source is the latest row by `signed_at`**, and the lifecycle reads only those. *Refused:* an `is_current` flag — a second source of truth that drifts on the third revalidation.
- **`signatory_name` and `role` are snapshots**, as ADR 0026 §5 froze `email`+`name` on `kri_return_notice_recipients`: `user_id` is `nullOnDelete`, and a sign-off whose signatory has left must still say who signed it. "Signed by (deleted user) 4" is not evidence. **`role` is a free-text capacity, NOT a platform role name** — "Head of Operational Risk", "Second Line — Independent Challenge". Spatie names (`risk-manager`) are an implementation detail, and renaming one would silently falsify every historical sign-off storing it. `string(100)` per ADR 0014: size the column for the values it will hold.
- **Two decisions, not three.** *Refused:* `approved_with_conditions` — no transition branches on it, and a condition is a `sm_scenario_notes` row of kind `assumption` (Phase 1) or an action (Phase 3). A third value nothing reads is how a workflow acquires a state nobody can leave.
- **`signed_at` nullable per standard §13.** Phase 1 writes a row only from the sign action, so it is non-null on every row Phase 1 creates, and **only non-null rows count toward the lifecycle**. *Refused:* `requested_at`/`requested_by` to make the null meaningful — nothing in Phase 1 requests a sign-off without granting it; a Phase 3 validation-committee request flow is an amendment naming Phase 3.
- **`is_independent_challenge` is a flag on the row, not a fourth `source`.** PSMOR and the Fed FEDS note (plan §3.1) require the second-line challenge to be *identifiable*, and any of the three sources may carry it; `source_4` would make the workbook's Source 1/2/3 block and the governance requirement compete for one column.

### A1.7 `sm_scenario_notes`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | `id()` | no | auto |
| `organization_id` | `foreignId()->constrained()->cascadeOnDelete()` | no | — |
| `sm_scenario_id` | `foreignId()->constrained('sm_scenarios')->cascadeOnDelete()` | no | — |
| `kind` | `string(20)` — `assumption` \| `dissent` \| `challenge` \| `workshop_minute` | no | — |
| `author_id` | `foreignId()->constrained('users')->nullOnDelete()` | **yes** | NULL |
| `author_name` | `string(200)` — snapshot | no | — |
| `body` | `text` | no | — |
| `created_at`, `updated_at` | `timestamps()` | yes | NULL |

Index `sm_notes_org_scenario_kind_idx (organization_id, sm_scenario_id, kind)`.

- **Append-only: Phase 1 registers no update and no destroy route.** A corrected note is a new note. This is the bias-mitigation trail D4 rests on, and a dissent its author can quietly edit is not a dissent trail. Enforced by the absence of routes plus a store-only Form Request, not a DB trigger — the product has none. `author_name` is a snapshot for §A1.6's reason: an anonymous dissent cannot be followed up, which is worse than none.
- `kind` is `string(20)`, not a DB `enum` — ADR 0026 §2's rule: a fifth kind is then a code change, not an `ALTER TABLE`. The four are frozen all the same. *Refused:* `is_ai_generated`; plan §6.5 puts AI-drafted narratives in **Phase 3**, and the column arrives with it in `Bcms\Scenario::$ai_generated`'s shape (BCMS standing rule 4).

### A1.8 Vocabularies as PHP consts

House style — a `public const` on the model with a docblock naming its source, as `QuantificationScenario::TYPES`, `LossEvent::SEVERITIES`, `RiskTaxonomy::FRAMEWORKS` and `RiskCause::SOURCES` do. All live on `App\Models\Sm\Scenario` except the checklist (`Sm\ScenarioCause`), `SOURCES` (`Sm\Signoff`) and `KINDS` (`Sm\Note`). **D3's "the scales live in config" is satisfied by these consts**, not a publishable `config/sm.php`: they are vlists, tests assert them, and a file a tenant can edit would let one install store a value another install's code has never seen. Phase 5's tenant-configurable *labels* overlay the const map without changing a stored value. Every `value => label` below is verbatim from the extraction, because D1 makes the workbook the specification and Phase 5's Excel export must round-trip it.

**`Scenario::STATUSES`** (`string(24)`), lifecycle order: `draft`, `in_design`, `designed`, `in_validation`, `validated`, `signed_off`, `archived`, `returned_for_rework`.

**`Scenario::STATUS_TRANSITIONS`** (`from => list<to>`), enforced in the controller per standard §3. The precedent is `Risk\LossEventController::updateStatus()` (`app/Http/Controllers/Risk/LossEventController.php:199-206`): an `$allowedTransitions` map, and an illegal target answered with `back()->with('error', …)` — a flash message, not a 403. **`Sm` improves on it by putting the map on the model as a real const, not an inline local.** `LossEvent::STATUSES`' docblock (`app/Models/LossEvent.php:61`) names `LossEventController::STATUS_TRANSITIONS` as the authority for its transitions — **that const does not exist anywhere in `app/`**; it is an inline variable in one method, so the docblock cross-reference is stale and no test can assert the map. A const on `Sm\Scenario` is assertable, which is the difference between a lifecycle rule somebody can check and one somebody has to read a controller to find.

```text
draft               → in_design | archived
in_design           → designed | returned_for_rework
designed            → in_validation | in_design
in_validation       → validated | returned_for_rework
validated           → signed_off | returned_for_rework
signed_off          → archived | in_validation
archived            → in_design
returned_for_rework → in_design
```

`signed_off → in_validation` **is** D4's "post-sign-off edits require revalidation", as an edge rather than a rule buried in a service; `archived → in_design` is the annual refresh off `review_due_on` (plan §4.2, capability 10). Nothing else moves a scenario — a terminal state with no exit is how a record becomes uncorrectable. Transition guards (controller, flash not 403): `in_design → designed` requires the §A1.5 sum-to-100, a non-null `risk_taxonomy_node_id`, ≥1 selected cause, and non-empty `background` and `rationale`; `validated → signed_off` requires ≥1 current sign-off with `decision = approved`.

**`Scenario::NATURES`** — `group` "Group scenario" · `standard` "Standard scenario" · `local` "Local scenario". Default `local`.

**`Scenario::WEIGHTINGS`** — `low` "Low" · `medium_low` "Medium-low" · `medium_high` "Medium-high" · `high` "High". Nullable, no default (§A1.2). **Name collision recorded:** the scorecard's *component* weighting is a different vocabulary — 1 Very low … 5 Very high plus an implicit 0 for a blank cell (spike §1). Phase 2's `sm_scorecard_components` must not reuse `Scenario::WEIGHTINGS` or the bare name `weighting`.

**`Scenario::REPUTATIONAL_EXPOSURES`** — **six stored values, and the plan was wrong.** Plan §4.2 said "6-point (None → Severe)" and the brief repeated it; the workbook dropdown (LS `[55,13]`–`[61,13]`) has **seven** options whose first two are not levels:

| Stored value | Workbook label |
|---|---|
| *(NULL)* | Impact: not yet assessed |
| `no_impact` | No impact |
| `insignificant` | Insignificant: Minor isolated stakeholder concerns / impacts |
| `minor` | Minor: Serious segmented stakeholder concerns/incidents |
| `moderate` | Moderate: Concerns becoming broader and more vocalised within the industry |
| `major` | Major: Negative public exposure with significant impact |
| `severe` | Severe: Dramatic loss of stakeholder confidence undermining business viability.  Extensive negative public exposure |

Modelled as **5 graded levels + `no_impact` + NULL-as-not-yet-assessed**: "No impact" is an assessment, "not yet assessed" is the absence of one, and collapsing them into a `none` value would be a fabricated answer (§5). **Not reused from `LossEvent::SEVERITIES`** (`insignificant, minor, moderate, major, catastrophic`) — the workbook's top level is `severe`, and adopting the loss-event list would rename a scale a customer already signed off, to save four lines.

**`Scenario::LEGAL_COMPLIANCE_EXPOSURES`** — six values from LS `[64,13]`–`[69,13]`: `no` "No" · `insignificant` · `minor` · `moderate` · `major` · `severe`; NULL = not yet assessed. **`no`, not `no_impact`:** the two dropdowns literally differ ("No" vs "No impact"), and D1 plus the Phase 5 round-trip require the stored value to map back to that exact string. Two near-identical value sets is the price of an exact export; normalising them would be cheaper and wrong.

**`Scenario::DECISIONS`** — `approved`, `returned_for_rework`. **`Signoff::SOURCES`** — `source_1` "Source 1" · `source_2` "Source 2" · `source_3` "Source 3". **`Note::KINDS`** — `assumption`, `dissent`, `challenge`, `workshop_minute`.

**`ScenarioCause::CHECKLIST`** — the frozen 23 codes as `code => [side, group, label]`, in workbook row order (LS cols B/C rows 32–48). **This list *is* the checklist; a code not in it is a 422.**

| Code | Side | Group | Label |
|---|---|---|---|
| `people_inappropriate_resources` | internal | people | Inappropriate level of resources |
| `people_inadequately_skilled` | internal | people | Inadequately skilled people |
| `people_inadequately_managed` | internal | people | Inadequately managed people |
| `people_intentional_act` | internal | people | Intentional act |
| `people_unintentional_acts` | internal | people | Unintentional acts |
| `people_other` | internal | people | Other |
| `process_inadequately_designed` | internal | process | Inadequately designed process |
| `process_ineffective` | internal | process | Ineffective process |
| `process_lack_of` | internal | process | Lack of process |
| `process_other` | internal | process | Other |
| `system_inadequately_designed` | internal | system | Inadequately designed systems |
| `system_inadequately_maintained` | internal | system | Inadequately maintained systems |
| `system_other` | internal | system | Other |
| `ext_natural_environment` | external | external | Natural environment |
| `ext_technological` | external | external | Technological |
| `ext_cultural` | external | external | Cultural |
| `ext_regulatory` | external | external | Regulatory |
| `ext_other` | external | external | Others |
| `ext_supplier` | external | external | Supplier |
| `ext_customer` | external | external | Customer |
| `ext_salesforce_distribution` | external | external | External Salesforce / Distribution |
| `ext_group_affiliate` | external | external | Group/affiliate entity |
| `ext_unknown` | external | external | Unknown/yet to be determined |

Codes are group-prefixed so `code` is globally unique and §A1.4's two-column unique key works: the workbook has an "Other" in each of People, Processes and Systems, and three rows sharing `code = 'other'` would make one unique key impossible.

**The one recorded deviation from the artefact.** The workbook's external list contains "**Leadway Assurance company**" — the reference artefact's own tenant — frozen here as `ext_group_affiliate` "Group/affiliate entity", which is what the extraction itself instructs. Shipping one customer's insurer name into every tenant's checklist would put another bank's competitor on their screen. This is Amendment 1's only departure from a verbatim workbook string, and it is written down for that reason (D1).

**Recorded now because it constrains a Phase 2 column: `data_source` is LABELLED FREE TEXT, not a closed enum.** The extraction shows composite values — `HR information`, `Scorecard`, `Expert judgement`, `Legal department`, and **"HR information and Legal department"**. A closed enum cannot hold the composite, and a DB `enum` column would turn the fourth value into a data-loss migration. Phase 2 freezes `data_source` as `string(200)` (or `text`) plus a nullable `data_source_detail`, with the suggested values as a const *hint list* for the picker. **This amends D2's "data source enum" and D3's "data-source vocabulary"**; the amendment wins.

### A1.9 OP1–OP17 seeding

Verified against the table: `risk_taxonomies` has `id`, `organization_id` (**NOT NULL**, `->constrained()->cascadeOnDelete()`), `name`, `description`, `framework` `string(50)` nullable, `parent_id` (self-FK `nullOnDelete`), `sort_order`, `depth`, `is_active`, `timestamps`, plus `index(organization_id, parent_id)` — and **no `code` column**, so the OP number goes in `name`. `RiskTaxonomy::FRAMEWORKS` already contains `'Basel III'`, so that const does not change. `RiskTaxonomy` uses `BelongsToOrganization` but does **not** set `$tenantIncludesGlobal`, and the column is NOT NULL — so **BCMS's system-row mechanism is unavailable here**: `BcmsReferenceSeeder` writes `organization_id = null` + `$tenantIncludesGlobal` + `TenantContext::clear()`, and none of the three works against a NOT NULL column on a table the Regulatory Compliance module owns and its add-node screen writes. *Refused:* making `risk_taxonomies.organization_id` nullable — a structural change to a shipped, other-module table for 17 rows, which would also change `RiskTaxonomyPolicy` and the meaning of the taxonomy admin screen. **No taxonomy seeder exists today** (`grep -rn "risk_taxonomies\|RiskTaxonomy::" database/` returns only the create/drop migration).

**Decision: a per-tenant system list.** `database/seeders/Sm/SmReferenceSeeder.php` (placement mirrors `database/seeders/Bcms/BcmsReferenceSeeder.php`) loops `Organization::query()->get()` and calls `App\Services\Sm\OpEventTypeCatalogue::ensureFor($organization)` — a service, not inline seeder logic, so provisioning can call the same method. `BusinessProcessSeeder` is the precedent for the shape: "Seed every organization present in the system", idempotent, "keyed on (organization_id, code)".

- **Structure:** one parent node `'Basel OP event types'` (`framework = 'Basel III'`, `parent_id = null`, `depth = 0`, `sort_order = 0`) with the 17 OP nodes as children (`depth = 1`, `sort_order = 1..17`). *Refused:* 17 loose roots — the admin screen is a tree (`RiskTaxonomy::children()` orders by `sort_order`), 17 roots beside a tenant's own nodes is unusable, and a parent gives the scenario form one indexed query on the existing `index(organization_id, parent_id)`.
- **Names verbatim from the extraction** — `'OP1 Internal Fraud & intentional unauthorised activity'` … `'OP17 External vendors misperformance or failure, incl. distribution misperformance (brokers)'`. `name` is `string(255)` (the migration writes `$table->string('name')` and does not narrow Laravel's default); the longest of the 17 is OP7 at **158 characters** (OP16 96, OP17 92), so all fit with room for a tenant's CBN ORMS extension — measured, not assumed, which is ADR 0014's whole lesson.
- **Insurer-flavoured labels kept VERBATIM, not bank-neutralised.** D1 makes the workbook the specification; the labels are what a customer's existing loss-data templates and signed-off scenarios already say; and a "neutralised" rewrite is an unreviewed editorial change to a regulatory vocabulary made by whoever wrote the seeder. `name` is tenant-editable through the existing taxonomy screen, so a bank wanting "OP13 Failures in credit or claims processes" edits its own node and keeps the OP number. The extraction's **Basel Level-1 mapping** goes into each child's `description` ("Basel Level-1: Clients, products & business practices"), which makes the list navigable for a bank without renaming anything. *Refused:* a new column or config file for that mapping — `description` is already there, rendered and tenant-editable.
- **Idempotency, and the one line that can undo a tenant's edit.** `firstOrCreate` on `['organization_id', 'framework' => 'Basel III', 'name' => <full label>]`, then update only `parent_id`/`depth`/`sort_order` where they are null/0. **Do not use `updateOrCreate` with `is_active` in the attributes half** — that re-activates a node a tenant retired. Re-running then adds new nodes and repairs parent links without duplicating or resurrecting anything.
- **New tenants.** `scripts/deploy.sh:32` runs `RolesAndPermissionsSeeder` only — no module seeder — and **no code path in `app/`, `database/` or `packages/` creates an `Organization`** (verified by grep); tenants are provisioned outside this repository. So `ensureFor()` must stand alone, and Phase 1 also exposes `php artisan sm:seed-op-event-types {--organization=}`. **[verify at integration]** that provisioning calls one of the two; until then a new tenant's OP picker is empty, which is a visible gap, not a silent one.
- **COSO components are NOT seeded in Phase 1** — D3 names them but they belong to `sm_scorecards` (Phase 2) and no Phase 1 screen reads them. `SmReferenceSeeder` seeds the OP tree and nothing else; `BcmsReferenceSeeder`'s docblock is the standing reason — a seeder that writes data no screen reads is data a customer did not ask for.

### A1.10 Permissions — six, not nine

Plan §4.1 proposed nine; **Phase 1 declares six**. `PermissionCatalogCoversRoutesTest::every_permission_in_the_catalog_is_held_by_some_role` fails on a declared permission no role holds, and `the_catalog_groups_every_permission_under_a_module` fails on one outside a module block — so declaring Phase 2–5 permissions now means granting routes that do not exist, on a role-assignment screen an administrator reads as available authority. New `'Scenario modelling'` block in `RiskPermissionCatalog::modules()`, after `'Quantification'` (the module Sm feeds, plan §2). Descriptions are authorities, not labels (standard §2):

```php
'scenario.view'     => 'See operational-risk scenarios, their causes, allocations and sign-offs.',
'scenario.create'   => 'Design a new scenario and give it a reference.',
'scenario.update'   => 'Change a scenario\'s identity, qualitative report, causes and business-line allocation.',
'scenario.validate' => 'Move a scenario through validation — return it for rework or mark it validated.',
'scenario.sign_off' => 'Record a sign-off against a scenario as one of its three sources.',
'scenario.note'     => 'Add an assumption, dissent, challenge or workshop minute to the scenario record.',
```

**`scenario.note` is separate from `scenario.update` because they are different authorities:** editing a scenario is first-line work, recording a dissent against somebody else's is second-line work. One permission for both lets the designer write the dissent log — the trail D4 exists to keep. Standard §2 makes exactly this argument for `admin.metadata` / `admin.scoring` / `admin.configuration`.

**Deferred, with the phase that needs each:** `scenario.design` → **Phase 2** (nothing in Phase 1 is quantitative, and `scenario.update` covers the qualitative tabs); `scenario.manage_actions` → **Phase 3**; `scenario.run_export`, `scenario.manage_library` → **Phase 5**. Adding them later costs **no migration**: `scripts/deploy.sh:32` runs `RolesAndPermissionsSeeder`, whose `syncCatalog()` is additive, so a release that declares a permission grants it on deploy — which is why declaring them early buys nothing. *Refused:* `scenario.delete`; soft delete goes through the policy's `delete` ability under `scenario.update`, and `loss_event.delete` exists only because a loss event is a regulatory record with its own destruction rules.

**Role grants** (required by the coverage test — all six must be held by somebody): `risk-manager` gets all six and `chief-risk-officer` inherits them by spreading `$riskManager`, so no extra line. `risk-analyst` gets `scenario.view` + `scenario.note` only — the catalog's own words for the ORM Analyst are "challenges every line, decides nothing". `risk-owner` gets `scenario.view` + `scenario.note`: the first-line owner contributes causes and dissent about their unit and does not sign the scenario off, the separation the catalog already enforces between `rcsa_assessment.approve` and `.review`. `compliance-officer` gets `scenario.view` + `scenario.note`; `board-member` gets `scenario.view`; `super-admin` is `['*']`, unchanged. *Refused:* `scenario.sign_off` for `risk-analyst` — independent challenge is a flag on a sign-off row (§A1.6), not a reason to let the analyst sign the record they are challenging.

### A1.11 Feature flag, route group, nav entry

**Flag** — appended to `config/features.php` in house style (a comment naming what it gates and why it is off, then one `env()` line), plus a commented `# FEATURE_SCENARIO_MODELLING=false` in `.env.example` as `# FEATURE_BCMS=false` is (line 89). Default **false**, gated by `EnsureFeatureEnabled` (alias `feature`, `bootstrap/app.php:57`), which aborts **404, not 403** — a disabled surface is indistinguishable from one that does not exist.

```php
'scenario_modelling' => env('FEATURE_SCENARIO_MODELLING', false),
```

**Route group** — inside the existing `Route::prefix('risk')->middleware(['auth'])` group (`routes/web.php:482`), after the BCMS group closes at `:2930`, mirroring `:2122`:

```php
Route::middleware('feature:scenario_modelling')->prefix('scenarios')->name('scenario.')->group(function () {
```

- URLs are `/risk/scenarios/...`. The enclosing group supplies `auth`; `web` supplies `ResolveTenant` **before** `SubstituteBindings` (`bootstrap/app.php:82-97`), so a `{scenario}` binding cannot resolve another tenant's row. Nothing to add for tenancy. **Name prefix `scenario.` (singular), URL prefix `scenarios` (plural) — deliberately different.** BCMS's `bcms.` matches both because its permission namespace is also `bcms.*`; here the namespace is `scenario.*` (plan §6.1), and aligning route *names* with it makes `permission:scenario.view` on `scenario.index` read as one vocabulary. Recorded so nobody "fixes" the mismatch.
- **Phase 1 routes**, each carrying `permission:` or `can:` (standard §2, `RouteAuthorizationTest`), read routes on `scenario.view`: `GET /` `scenario.index` · `GET create` `scenario.create` · `POST /` `scenario.store` (`scenario.create`) · `GET {scenario}` `scenario.show` · `GET {scenario}/edit` `scenario.edit` · `PUT {scenario}`, `PUT {scenario}/causes`, `PUT {scenario}/allocations` (`scenario.update`) · `POST {scenario}/status` `scenario.status` (`scenario.validate`) · `POST {scenario}/signoffs` `scenario.signoffs.store` (`scenario.sign_off`) · `POST {scenario}/notes` `scenario.notes.store` (`scenario.note`). **Literal routes before `{scenario}`**, or `create` binds as a scenario — the trap `routes/web.php:2126` documents for BCMS `settings`. `{scenario}` binds `App\Models\Sm\Scenario` by `id`, children scoped through the parent relation with `->scopeBindings()` on nested children (ADR 0017 §5), so another tenant's id 404s rather than resolving.
- **`Sm\Scenario` MUST be added to `App\Support\MorphTypes::map()` as `'sm_scenario'`.** `AppServiceProvider:133` calls `Relation::enforceMorphMap()`, so an unaliased model **throws** the moment anything asks for its morph class — and `AuditTrailService::record()` asks on every write. Prefixed `sm_` because the map's convention is singular snake_case and `quantification_scenario` is taken (`MorphTypes:212`); a bare `scenario` would compete with `Bcms\Scenario` and `QuantificationScenario`, and `getMorphClass()` returns the first match — "a coin toss written into customer data", as the map's own comment puts it. `'sm_scenario'` is 11 chars, inside `RiskAuditTrail::ENTITY_TYPE_MAX` (50), and every `action_type` Phase 1 writes (`create`, `update`, `status_changed`, `signoff_recorded`, `note_added`, `allocation_changed`) is inside `ACTION_TYPE_MAX` (64) — ADR 0014 is why that was checked.
- **Audit is `AuditTrailService` → `risk_audit_trail`, not a new `sm_audit_logs`.** Sm is an ERM/Risk sub-module (plan §6.1) and that trail is what the register, RCSA and the KRI cycle write through; `BcmsAuditable` → `bcms_audit_logs` is BCMS's, and copying it would put a second audit mechanism inside the module the first belongs to.
- **No `HasObjectIdentity`, no `ScopedToGraph` in Phase 1.** Both mirror into `objects`, which needs an `object_types` row, an `ObjectSourceMap` entry and an `ObjectTypeRegistry` relationship set — and `ObjectIdentityTest::every_model_in_the_source_map_is_covered_by_this_test` fails on a mapped model the test does not assert on. Nothing in Phase 1 reads the graph. **Deferred to Phase 4**, where the risk-360 integration needs it; `uuid` (row 2) is frozen now so that mirror arrives with no migration. **No service provider** (architect rule 3, ADR 0007 deviation 2): routes behind the flag, morph alias in `MorphTypes`, nothing else to hold — an `Sm` provider would be empty.

**Nav entry** — a new section in `NavPresenter` immediately **before** `'quantification'` (line 324), after `'analysis'` (line 313):

```php
'key' => 'scenario_modelling', 'label' => 'Scenario Modelling', 'icon' => 'timeline',
'prefixes' => ['/risk/scenarios'],
'items' => [
    ['label' => 'Scenarios', 'route' => 'scenario.index', 'permission' => 'scenario.view', 'feature' => 'scenario_modelling'],
    ['label' => 'New Scenario', 'route' => 'scenario.create', 'permission' => 'scenario.create', 'feature' => 'scenario_modelling'],
],
```

Placement: Sm is **upstream of Quantification** (plan §2 — "The new module is upstream of it, not a replacement"), so it reads before it, and both sit after Risk Analysis because both are analysis-of-record rather than register entry. Gated on `scenario.view` for the section and per item, with `'feature' => 'scenario_modelling'` on every item exactly as the TPRM and BCMS sections do — the flag keeps the section out of a default install, the permission out for a role that cannot use it. Two items because Phase 1 has two screens; dashboard, action tracker and library items arrive with their phases and their permissions.

### A1.12 Migration list

Four files, in this order. None edits a shipped table, so none needs the `Schema::hasColumn` guard `2026_08_09_100004` uses.

| # | File | Creates |
|---|---|---|
| 1 | `2026_11_10_100001_create_sm_scenarios_table.php` | `sm_scenarios` |
| 2 | `2026_11_10_100002_create_sm_scenario_causes_table.php` | `sm_scenario_causes` |
| 3 | `2026_11_10_100003_create_sm_business_line_allocations_table.php` | `sm_business_line_allocations` |
| 4 | `2026_11_10_100004_create_sm_scenario_signoff_and_note_tables.php` | `sm_scenario_signoffs`, `sm_scenario_notes` |

- **Order is load-bearing:** every child FK is `->constrained('sm_scenarios')`, so 1 lands first. All FK targets outside the module already exist and all pre-date these files — `users` (`0001_01_01_000000`), `organizations` (`2026_02_22_200001`), `business_units` (`2026_02_22_200002`), `risks` (`2026_02_22_200006`), `risk_taxonomies` (`2026_03_26_000006`), `fx_rates` (`2026_08_12_120002`) — so no deferred-key dance like `2026_09_09_120007_…_deferred_keys.php` needs. File 4 groups the two append-only governance tables because they are one concern (D4's bias-mitigation trail) and neither is referenced by anything else, as BCMS groups by concern. Every `down()` is `Schema::dropIfExists(...)` in reverse order within the file.
- **SQL intersection:** no CHECK (§A1.5), no generated column, no `timestampTz`, no `useCurrentOnUpdate` — `NoSelfUpdatingTimestampColumnsTest` scans every column of every type (§13, ADR 0022) — and `timestamps()` is nullable by default, so §13 is satisfied. Both CI legs (MariaDB 10.4, MySQL 8.0.46) green; locally `env -u DB_DATABASE php artisan test`.

### A1.13 Deferred — do not add these in Phase 1

Each named with the phase that needs it, per the freeze rule. A Phase 1 migration containing one of these is a scope breach and gate 2 should fail it.

| Column / object | Phase | Why not now |
|---|---|---|
| `sm_scenarios.entity_id → entities` | 4 | No Phase 1 screen reads a legal entity; §A1.3 |
| `sm_scenarios.ai_generated` | 3 | Plan §6.5; standing rule 4's draft-until-edited shape arrives with it |
| `sm_scenarios.loss_event_id`, `.key_risk_indicator_id`, `.emerging_risk_id`, `.bcms_*_id` | 4 | Plan §4.4's integrations; each needs the screen that reads it |
| `sm_scenarios.quantification_scenario_id` | 4 | D5 stamps provenance on the `QuantificationScenario`, not here |
| `sm_scenarios.lock_version` | — | No Phase 1 consumer. If ui-designer needs optimistic locking on the workspace it is an amendment like ADR 0026 A1.8, and it goes in the unmerged migration |
| `sm_scenarios.version`, `.supersedes_id` | — | D4's version history *is* the audit trail; a second mechanism on one aggregate is a drift |
| `sm_scenario_risks` pivot | 4 | §A1.2, `risk_id` |
| `sm_business_line_allocations.business_process_id` | 4+ | §A1.5; only if a tenant allocates below the unit |
| `sm_scenario_notes.is_ai_generated` | 3 | §A1.7 |
| `sm_scenario_signoffs.requested_at`, `.requested_by` | 3 | §A1.6; a validation-committee request flow |
| Tenant-configurable severity labels, percentile mapping, cause-checklist admin, data-source vocabulary | 5 | Plan §6.2, §6.6, screen 7. Stored values are frozen here; the overlay changes labels only |
| Every computed figure (frequency, gross/net impact, scorecard global score) | 2 | They live on §A1.1's Phase 2 tables, never on `sm_scenarios` |
| `object_types` row, `ObjectSourceMap`, `ObjectTypeRegistry` entries for `Sm\Scenario` | 4 | §A1.11; `ObjectIdentityTest` coverage arrives with them |
| COSO component taxonomy seeding | 2 | §A1.9; belongs to `sm_scorecards` |
| `sm_scenario_exposures` | never | Refused, §A1.1 |

Amendment 1 does **not** freeze a Phase 2 or Phase 3 column, and pre-commits those phases only where a Phase 1 choice would otherwise foreclose them: the `weighting` name collision and `data_source`-as-free-text (§A1.8), and `uuid` on the root so the Phase 4 graph mirror needs no migration (§A1.11).

## Amendment 1 addendum (2026-11-10) — three items from the ui-designer specs

`docs/ui/scenario-modelling/00`–`03` landed after Amendment 1 was written. Three of their decisions need a column, a format or a settings home that Amendment 1 did not name. Everything else in Amendment 1 stands; where a spec diverges from it, **the spec changes** (§Addendum-4).

### Addendum-1. Sign-off warnings are a stored column, not a recomputed list

Spec `01-workspace-shell.md` §5.6 item 5 shows five non-blocking warnings in the sign-off modal and promises: *"These are recorded with the sign-off so a reviewer can see what was outstanding."* §A1.6 froze no column for them. **This is a Phase 1 need and the column is added.**

`sm_scenario_signoffs` gains one column, placed after `comment`:

| Column | Type | Null | Default |
|---|---|---|---|
| `warnings_acknowledged` | `json` | yes | NULL — but the sign action **always writes an array**, `[]` when nothing was outstanding. NULL is never written by Phase 1 and reads as `[]` |

**Stored, never recomputed at render — and this is the load-bearing part.** Spec 01 §5.5's table allows notes to be added to a `signed_off` scenario ("a note is commentary, not a change"). So `no_challenge_recorded`, derived live, would vanish the moment somebody appended a challenge note a month after sign-off — falsifying what was outstanding when the signer signed. The warnings are a **snapshot**, exactly the discipline D1/§4.3 applies to figures. A derived list here is not a simplification, it is a record that quietly rewrites itself.

**`Sm\Signoff::WARNING_CODES`** — frozen, derived from spec 01 §5.6.5's five bullets (bullets 3–5 each carry two alternative messages, so eight codes):

| Code | Condition | Bullet |
|---|---|---|
| `no_challenge_recorded` | no `sm_scenario_notes` row of kind `challenge` or `dissent` | 1 |
| `no_cause_selected` | no selected cause | 2 |
| `allocation_missing` | no allocation row | 3 |
| `allocation_not_100` | Σ percent ≠ 100.00 | 3 |
| `reputational_not_assessed` | `reputational_exposure` IS NULL | 4 |
| `legal_not_assessed` | `legal_compliance_exposure` IS NULL | 4 |
| `insurance_risk_not_assessed` | `insurance_risk` IS NULL | 5 |
| `eligible_to_transfer_not_assessed` | `eligible_to_transfer` IS NULL | 5 |

Computed server-side by `App\Services\Sm\SignoffWarnings::for($scenario): array`, returning **codes only**. The rendered *messages* come from config (§Addendum-3) and are never stored — storing wording would freeze text a tenant may reconfigure and duplicate §A1.8's label maps.

**Six of the eight are unreachable through the Phase 1 UI path, and are frozen anyway.** Spec 03 §7 requires causes, allocation-to-100, and all four exposure answers at the `in_design → designed` gate, so a scenario cannot reach `signed_off` with them unset; only `eligible_to_transfer` (explicitly "optional — warned at sign-off") and `no_challenge_recorded` can genuinely fire. They are kept because the sign-off record must be self-describing rather than correct-by-coincidence, and because **Phase 5's Excel import can produce a scenario that never passed a gate**. Removing them now would mean re-adding them in Phase 5, over a table that by then holds signed governance records.

- **`json`, not a child table.** Read as a whole, rendered as a list, never filtered by one code — plan §4.2 permits json exactly there. A `sm_signoff_warnings` child would be a 1:N with no behaviour of its own: the same shape §A1.1 refused for `sm_scenario_exposures`, so consistency demands the same answer. It would also be a sixth table, breaching §A1.1's boundary.
- **`json`, not a bitmask or a delimited `text`.** A bitmask is unreadable in the database and in the audit trail, makes a ninth warning an invisible vocabulary change, and has no precedent in this product. A delimited string needs a parser and an escaping rule, and the next person puts a whole message in it.
- **Standard §15 / ADR 0025: no digest over this column in Phase 1.** MySQL 8 stores `json` binary and returns it re-serialised, and its parser does not read every double back exactly. Nothing hashes a sign-off row today, so the rule is simply *do not start*. If a later phase does, the payload is a list of ASCII strings — no floats, no non-integral doubles — so `App\Support\CanonicalJson` suffices and the float-quoting requirement of ADR 0025 §2a does not bite. That is worth saying now, because the reason it is safe is the reason it must stay strings: a future `{'code': …, 'score': 0.7}` shape would make the same digest engine-dependent.

### Addendum-2. The reference is `SM-{YYYY}-{NNN}`, in Lagos, with a retry

§A1.2 froze `reference string(40)`, unique per tenant, service-generated. Spec `02` §5.1 fixes the format. Both stand; the format and the collision rule are frozen here.

**Format:** `SM-{YYYY}-{NNN}` — `YYYY` the creation year **in Africa/Lagos**, `NNN` a zero-padded sequence per tenant per year, `max + 1`, starting `001`. `SM-2026-001` is 11 characters. **The column stays `string(40)`, not narrowed to 11**: Phase 5 imports filled LS workbooks whose references are the customer's own, and 40 matches `bcms_management_reviews.reference`. Unique key unchanged: `sm_scenarios_org_reference_unique (organization_id, reference)`.

**Do not copy `now()->year` from the precedent.** `config/app.php:68` is `'timezone' => 'UTC'`, and *both* existing generators use `now()->year` — `Quantification\ScenarioService::nextReference()` (`app/Services/Quantification/ScenarioService.php:71-86`) and the near-miss generator (`app/Http/Controllers/Risk/LossEventController.php:514-522`). Between 23:00 and 24:00 UTC on 31 December — 00:00–01:00 on 1 January in Lagos — they stamp the *old* year onto a reference the user is watching being created on New Year's Day. Spec 02 §5.1 requires Lagos, so: **`KriWorkingCalendar::today()->year`**, reusing the existing helper (`app/Support/Kri/KriWorkingCalendar.php:24,32-35`) and its `TIMEZONE` const rather than adding a fourth spelling of `'Africa/Lagos'` to the codebase.

**Do not copy `substr(-3)` + `orderByDesc(string)` either.** Both precedents compute the next number as `((int) substr($last->reference, -3)) + 1` over `orderByDesc` of the reference *string*. That is correct only while the sequence is ≤999: `SM-2026-1000` sorts **before** `SM-2026-999` as a string, and `substr(-3)` of it is `000` — so the 1000th scenario is followed by a second `SM-2026-001`, which the unique key then rejects forever, with no path forward. Freeze instead: select the year's references for the tenant (`where('reference', 'like', "SM-{$year}-%")->pluck('reference')`), and take `max((int) substr($r, 8)) + 1` in PHP — offset 8 skips the fixed `SM-YYYY-` prefix, so it is correct at any width, needs no `CAST(SUBSTRING(…))` filesort, and stays inside the MariaDB 10.4 ∩ MySQL 8.0.46 intersection. The plucked set is one tenant's one year of heavyweight, workshop-designed scenarios; it is bounded by construction, and if it ever is not, that is the ADR that introduces a counter.

**Collision-retry rule** (the two precedents have no rule — they read-then-insert with no lock and no retry, so concurrent creates both get the same number and one 500s). Freeze: the create runs in a transaction; on a `QueryException` with SQLSTATE `23000` against `sm_scenarios_org_reference_unique`, re-read the sequence and retry, **at most 3 attempts**; after that, a flash message — "We could not assign a reference. Please try again." — never a 500, and never a silently different reference.
- *Refused:* `lockForUpdate` over the tenant's scenario rows. It serialises every create for a tenant to defend a race that a retry resolves in microseconds, and there is no sequence row to lock.
- *Refused:* a per-tenant counter table. A sixth table, and §A1.1's boundary is the point of the discipline.
- *Refused:* dropping the unique key and letting duplicates through. The reference is what a board pack cites; two scenarios answering to `SM-2026-014` is the failure the key exists to prevent.

**`SM-` is free — verified.** The product's reference prefixes are `NM-` (`LossEventController.php:522`), `TPI-` (`Tprm/Portal/PortalIncidentService.php:152`), `LE-` (`Tprm/Incidents/IncidentErmBridge.php:200`), `SIM-` (`Quantification/SimulationService.php:80`) and `SCN-` (`Quantification/ScenarioService.php:85`, duplicated in `Quantification/ScenarioLibrary.php:117`). `grep` for `SM-` across `app/`, `database/`, `config/` and `resources/js` returns nothing. Spec 02 §5.1's insistence that it must not be `SCN-` is correct and then some: Quantification owns `SCN` **three times over** — the reference prefix, the object-graph type code (`ObjectTypeRegistry.php:156`, `ObjectSourceMap.php:197`) and the API reference prefix (`ApiResourceRegistry.php:254`).

**Two related notes, frozen so Phase 4 does not have to rediscover them.** `SM` is also unregistered as an object-graph type code, but the graph type *name* `Scenario` is already `QuantificationScenario`'s — so Phase 4's graph mirror (§A1.11) must register as `SmScenario` with code `SM`, not `Scenario`. And spec 02 §5.1's "the create page must not send a provisional reference" is upheld as a **server rule**: the reference is assigned on save only, because showing `SM-2026-014` before it exists is displaying a figure nothing produced (standard §5), and `max + 1` means two designers creating at once would both be shown a number one of them never gets.

### Addendum-3. The review cadence lives in `organizations.settings`, read through a service

Spec 01 §5.6.4 needs `suggestedReviewDueOn` = today in Africa/Lagos + the tenant's `review_cadence_months`, server-computed. Amendment 1 froze no home for the cadence.

**Home: the existing `organizations.settings` JSON bag, key `sm_review_cadence_months`.** `organizations.settings` is already `json` nullable (`2026_02_22_200001_create_organizations_table.php:25`) and already carries per-tenant settings of exactly this kind — `reporting_currency` and `default_fx_rate_type`, written by `2026_08_12_120006_add_reporting_currency_to_organization_settings.php`, whose docblock makes the point: *"organizations.settings is already a JSON bag, so this is a data change rather than a schema one."* **No new column on `organizations`** (the coordinator's constraint, and a structural change to a shipped table for one integer) and **no new table**.

**Read through `App\Services\Sm\ScenarioSettings::reviewCadenceMonths(?int $organizationId = null): int`**, copying `CurrencyService`'s shape exactly: a private `setting($organizationId, $key)` that falls back to `TenantContext::organizationIdOrNull()` and json-decodes the bag (`app/Services/CurrencyService.php:234-248`), a class const default, and — as `defaultRateType()` does (`:225-232`) — **validation of the stored value before it is accepted**. Frozen: an integer **1–120**; anything else, including a missing key, a string, or a negative, falls back to the const. The bag is untyped JSON an administrator edits, and `"twelve"` or `-3` fed to `addMonths()` is a `TypeError` or a review date in the past.

**Default: `config/sm.php` → `review_cadence_months` → `12`.** Plan §4.2's cadence is annual and capability 10 in plan §3.3 is the annual refresh, so 12 is the shipped value, not an invented one.

**Computation, frozen:** `KriWorkingCalendar::today()->addMonths(ScenarioSettings::reviewCadenceMonths())`, rendered as a `Y-m-d` string, **server-side only**. Day-boundary discipline per standard §14 — compare day to day with `whereDate`/`DateBounds`, never `->isPast()` on a date cast. Spec 01 §5.6.4's "(prop decision) the browser never computes it" is upheld: no `+12 months` in JSX, because a client-side date constant is a fabricated figure (standard §5) and would drift from the tenant's setting the first time an administrator changed it.

- *Refused:* `config()` alone, with no per-tenant read. `BcmsSettings`' docblock gives the reason verbatim — *"`config()` is process-wide and a queue worker holds it across tenants, so a reminder job for a Lagos tenant that read `config('bcms.reminder_send_time')` would use whichever tenant warmed the cache."* `suggestedReviewDueOn` is rendered inside a request, so config-only would work today; but the same value is read by Phase 4's review-cadence prompt (plan §4.4, capability 10), which is scheduled and queued. A setting that is right in a request and wrong in a worker ships silently, which is Gate G0 criterion 4's whole subject.
- *Refused:* an `sm_settings` table on the `Bcms\Setting` pattern ("A TYPED ROW, not a key/value bag"). It is the right shape for a module with a dozen governed settings and an admin screen — and it is a **sixth table**, outside plan §4.2's list and §A1.1's boundary. If Phase 5's Admin screen (plan §4.5 screen 7) accumulates enough Sm settings to earn one, that is an amendment naming Phase 5, and the keys move.
- *Refused:* `QuantificationSetting`. Another module's typed row (`default_iterations`, `cbn_minimum_car`, `cbn_mpr`, …), rendered and validated by the Quantification settings screen. Plan §6.6 puts the *percentile mapping* there because that setting belongs to the bridge; a scenario review cadence does not, and writing it there would put an Sm field on a screen that does not know about it.

**`config/sm.php` is hereby the config file D3 meant.** This is a **narrow, deliberate reversal of §A1.8**, which refused a publishable `config/sm.php`. That refusal stands for what it said: config must not be the authority for the **value set**. What changes is the **wording**. Spec 03 §2 requires the checklist, both exposure scales and the yes/no labels to "arrive as data" — *"Nothing in §4–§6 may be hard-coded in JSX"* — so that a tenant can rename "External Salesforce / Distribution" or replace the affiliate wording without a front-end release. So `config/sm.php` carries: `exposures.reputational.options` / `.unset_label` and `exposures.legal.options` / `.unset_label` (each option `{value, label, description|null}`), `yes_no.{yes,no,unset}`, `causes.checklist` (each `{code, side, group, group_label, label, requires_note, sort}`), `signoff.warning_messages` (code → message), and `review_cadence_months`. **Every `value` and every `code` in it must be one of §A1.8's frozen const values, and a test asserts that** — config supplies wording, the consts supply the legal set. That split is the whole point: a tenant editing config can rename a scale point but cannot make the database hold a value no code knows. Note two consequences the specs already require and the freeze now confirms: the **ordinal** of each scale lives in `options[]` array order in config and nowhere else (spec 03 §12.6), so anything that sorts by exposure server-side reads that order rather than inventing a stored ordinal; and **`requires_note`** (spec 03 §4.1 — true for every "Other", for "Others", and for "Unknown/yet to be determined") is config with no Phase 1 editing surface, so the Form Request enforces "note required when a `requires_note` cause is selected" with spec 03 §7's message, and a tenant needing a 24th cause needs a config change until Phase 5.

### Addendum-4. Corrections, and where the specs must move

**Two corrections to §A1.8's transition map** — not re-litigation; the first is a coordinator ruling Amendment 1 missed, the second is a defect in my own map that the specs exposed:

- **`archived` is terminal in v1.** Remove the `archived → in_design` edge. The coordinator ruled no restore, and spec 01 §5.5's table already says `archived` → Lifecycle: **none**. The annual refresh off `review_due_date` therefore acts on a **`signed_off`** scenario, through reopen-for-revalidation — not on an archived one.
- **`signed_off` also → `returned_for_rework`.** Spec 01 §5.5's "Reopen for revalidation" sets `returned_for_rework` and writes the reason to the notes log as a `challenge`. §A1.8 only allowed `signed_off → in_validation`, which cannot express that in one step. Corrected row: `signed_off → returned_for_rework | in_validation | archived`.

**Specs 00–03 align to Amendment 1, not the reverse.** The divergences ui-designer must resolve on their side: the allocation picker offers tenant **`business_units` only** — `business_processes` is rejected in §A1.5 with reasons the coordinator has upheld, so `allocation.options[].kind` and `business_process_id` come out of spec 03 §2 and the post body; `decision` is **`approved | returned_for_rework`** (§A1.6), not the three-value `approved / approved_with_conditions / declined` of spec 01 §5.6.2 — "return for rework" is spec 01 §5.3's separate lifecycle action, not a sign-off decision; props and 422 keys use the frozen column spellings **`designed_on` / `validated_on` / `review_due_on`** (§A1.2), not `design_date` / `validation_date` / `review_due_date`, with the presenter doing no renaming — ADR 0026 A1.7 is the precedent for recording a storage/prop spelling split rather than letting it be discovered; and the cause codes are §A1.8's **`people_*` / `process_*` / `system_*` / `ext_*`**, not spec 03 §2's illustrative `int_people_intentional_act`. **[flagged for the coordinator, not reopened here]** the `decision` vocabulary is the one divergence where the freeze may be on the wrong side: a signer who refuses to sign, without returning the whole scenario for rework, has no way to record that under two values. Two is defensible — declining *is* returning for rework — but if the coordinator wants `declined` as a distinct recorded refusal, it is a one-value amendment to §A1.6 and no schema change, since the column is already `string(20)`.

### Correction 1 (2026-11-10)

Spec 03 §6.4 was rewritten to match §A1.2's actual nullability, and spec 01 §5.5/§5.6 to match the shipped lifecycle. Two frozen details were invalidated and the coordinator ruled on four open questions. **No schema change in this correction** — one const, one guard, one route behaviour, one domain rule. Where this block and Addendum-1/4 differ, **this block wins**.

**C1.1 — The warning-code vocabulary swaps one code, and stays at eight.** §A1.2 froze `eligible_to_transfer` **NOT NULL default `false`**, so the condition `eligible_to_transfer_not_assessed` describes a state the schema forbids; §A1.2 froze `financial_risk` **nullable**, and spec 01 §5.6.5 warns on it, so it needs a code. Corrected `Sm\Signoff::WARNING_CODES` — remove `eligible_to_transfer_not_assessed`, add **`financial_risk_not_assessed`** (`financial_risk IS NULL`, bullet 5). Count unchanged at eight; bullets 1–5 unchanged. **Both the const (`app/Models/Sm/Signoff.php:92-109`) and `config/sm.php`'s `signoff.warning_messages` (`:227-236`) shipped against the old list and must change** — the config map is asserted against the const (Addendum-3), so one without the other fails that test, which is the mechanism working.

Two consequences recorded rather than left to code review. First, **Addendum-1's "six of the eight are unreachable" becomes seven of eight**: the code that swapped out was one of only two that could genuinely fire, so `no_challenge_recorded` is now the sole warning reachable through the Phase 1 UI path. The justification for keeping the other seven is unchanged and is now doing more work — the sign-off record must be self-describing rather than correct-by-coincidence, and Phase 5's Excel import can produce a scenario that never passed a gate. Second, spec 01 §5.6.5's **sixth bullet** — the advisory shown when `eligible_to_transfer` is `false` — now has **no code**, so it is **UI-only and not recorded** in `warnings_acknowledged`; ui-designer must scope that section's blanket "these are recorded with the sign-off" sentence to bullets 1–5. *Refused:* a ninth code for it. `false` is the column's default and therefore the state of every scenario nobody has considered, so recording it would attach the same warning to most sign-offs and teach signers to skip the list — and the coordinator's ruling fixes the count at eight. Spec 03 §6.4's own remedy is the right one: a permanent help line on the field plus the modal advisory, with the data consequence ("nobody considered transferability" is indistinguishable from "cover was checked and there is none") written down for Phase 2 rather than encoded as a warning.

**C1.2 — The `validated → signed_off` guard also requires `review_due_on`.** §A1.8's guard listed only "≥1 current sign-off with `decision = approved`". Spec 01 §5.6.4 makes the review date required in the sign-off modal and flags the mismatch itself: *"this is a UI-imposed requirement … the server does not currently refuse a sign-off without a review date."* A requirement that exists only in JSX is not a requirement — a non-JS 422, a stale tab or a Phase 5 import bypasses it, and the result is a signed-off scenario that never comes back for its annual refresh, which is precisely the governance gap plan §3.3 capability 10 exists to close. Corrected guard: **≥1 current sign-off with `decision = approved`, at least one with `is_independent_challenge = true` (spec 01 §5.6.3, which the server must also refuse — a control that only exists in the UI is not a control), and `review_due_on` NOT NULL.** Flash message, not 403 (standard §3). No schema change: §A1.2 already froze the column and §A1.2's `sm_scenarios_org_review_due_idx` already indexes it.

**C1.3 — `POST {scenario}/status` takes `{to, reason}`.** `to` required and validated against `Scenario::STATUS_TRANSITIONS[$scenario->status]`; `reason` **required when `to` is `returned_for_rework`**, optional otherwise, string 10–500 — the shape ADR 0026 A1.10 froze for `ReopenKriReturnRequest`. When required, the reason is persisted as an **`sm_scenario_notes` row of `kind = 'challenge'`, in the same transaction as the status change**: a return whose reason landed in a separate write is a return with no recorded reason after any failure between the two. No schema change — `kind = 'challenge'` is one of §A1.7's four frozen values and the notes table is append-only, so this is the mechanism §A1.7 already specified. 422 key `reason`; the status does not move when the note write fails.

**C1.4 — `scenario.edit` is a redirect in Phase 1.** `GET {scenario}/edit` **stays in §A1.11's frozen route table** and issues a redirect to `scenario.show`, with no screen behind it. The workspace is tabbed and `scenario.show` already carries `?tab=`, so a separate edit page would be a second rendering of one form with its own validation path — the divergence risk standard §10 warns about, since no test in this repository executes JavaScript. Keeping the route registered (rather than dropping it) is deliberate: `RouteAuthorizationTest` and `PermissionCatalogCoversRoutesTest` continue to see it, `permission:scenario.update` stays declared against a real route, and a bookmark or an emailed `/edit` link resolves instead of 404ing. If Phase 2's quantitative tabs want a dedicated edit surface, the route is already there.

**C1.5 — An author cannot be their own independent challenge.** When a posted sign-off row's `user_id` equals `sm_scenarios.created_by`, `is_independent_challenge` is **forced to `false`** with a flash message — it is not a 422, because the sign-off itself is legitimate and only the claim of independence is refused. This is a **domain rule enforced by the lifecycle service and pinned by a test**, not a validation rule and not a UI affordance: D4's bias-mitigation trail (PSMOR, the Fed FEDS note) rests on independent challenge meaning something, and a self-declared challenge by the scenario's author is not a challenge — it is the author attesting to their own work, which is the exact failure mode plan §3.1 says expert estimates need. Combined with C1.2's "at least one independent challenge" guard, the effect is that **an author cannot sign their own scenario off**, alone or with a co-signer they also happen to be. The rule keys on `user_id`, which §A1.6 froze nullable: a row with `user_id = NULL` (an external committee member, a group CRO without an account) cannot be compared to `created_by` and is not the author by definition, so it passes — the snapshot `signatory_name` remains the evidence.

**C1.6 — The shipped transition map is confirmed authoritative.** Verified by reading the code, not on report: `App\Models\Sm\Scenario::STATUS_TRANSITIONS` (`app/Models/Sm/Scenario.php:127-136`) matches §A1.8 **as corrected by Addendum-4** in all eight rows, including `archived => []` (terminal, the Addendum-4 ruling) and **`signed_off => ['returned_for_rework', 'in_validation', 'archived']`** — all three exits. Addendum-4's text implied no different shape; it listed the same three and the const's ordering within a row carries no semantics. **That const is the authoritative spelling of the map from here on**, and its docblock's reasoning (the two corrections, and why `signed_off → in_validation` survives alongside `signed_off → returned_for_rework`) is part of the record: the first makes the scenario editable, the second does not, and spec 01 §5.5 now offers both as separate banner actions because the difference is whether the user can then do anything about what they found.

### Correction 2 (2026-11-11)

Gate 2 (backend) returned a **VETO**: one blocker, two records, one ruling. Every claim below was re-verified against the shipped code rather than taken from the veto. **No schema change in this correction.** Where this block and Correction 1 differ, **this block wins** — in particular C1.5's closing sentence ("a row with `user_id = NULL` … is not the author by definition, so it passes") is **superseded** by C2.1 and is left in place only as the record of what was got wrong.

**C2.1 — F-1 BLOCKER. C1.5 is corrected to key on the acting user when `user_id` is null.**

*The defect, verified.* `SignoffModal.jsx:110-115` posts `{source, signatory_name, role, decision, is_independent_challenge}` — **no `user_id`**, though `StoreScenarioSignoffsRequest:83` accepts one and spec 01 §5.6.2 makes the signer picker optional. `ScenarioLifecycle::recordSignoff()` (`:472-512`) computes `$isAuthor` only when `$signerId !== null`. So through the only write surface that exists, `$isAuthor` is **always false** and C1.5 never fires: an author self-ticks independent challenge, the row stores `is_independent_challenge = true`, and C1.2's guard (`:337+`) is satisfied. `RiskPermissionCatalog.php:686-693` names C1.5, with spec 01 §5.6.3, as **"The only STRUCTURAL check"** against one person holding all six permissions and creating, designing, validating and signing off their own scenario. Shipped, there is therefore no structural separation-of-duties check at all.

*Correction.* **When the posted `user_id` is null, C1.5 is enforced against the acting user** — `$actor`, already a parameter of `recordSignoff()`. An author recording a sign-off with `is_independent_challenge = true` has the flag forced to `false` with the existing explanatory flash. When `user_id` **is** posted, the existing comparison against that signer is unchanged; the two checks are alternatives over a nullable field, not a replacement of one by the other.

*The pass-through is preserved, not narrowed.* A non-author actor scribing a genuinely external challenge (`user_id` null, actor ≠ `created_by`) still stores `is_independent_challenge = true`. **`SignoffRulesTest.php:287` must keep passing unchanged** — its own comment is right that refusing null-user sign-offs "would make an external challenge unrecordable, which is the one challenge most worth having". The correction polices *who is posting*, not *whether the signer has an account*.

*Reason.* **A domain rule no shipped write path can reach is not a control.** That is C1.2's own principle — "a control that only exists in the UI is not a control" — applied one level down: a control that fires only for a payload the UI never sends is equally not a control, and is worse than none, because its docblock and its passing test both assert a guarantee that does not hold. The shipped comment at `ScenarioLifecycle.php:498-505` declares the null edge deliberate and "MUST NOT BE 'FIXED'". It was right that null must stay **recordable** and wrong that null must stay **unpoliced**; those are different claims, and Correction 1 §C1.5 conflated them.

*Fails closed.* The only false negative this creates is an author scribing a genuinely external committee member's challenge: the flag is dropped, C1.2's guard then refuses the sign-off, and sign-off gets **harder, never falsely easier**. That is the error direction D4 can tolerate; the veto's was the other one.

*Refused:* a signer-search endpoint plus mandatory `user_id` linkage. It is outside §A1.11's frozen route table (a thirteenth route, needing an amendment's worth of justification on its own); it adds a user-directory enumeration surface to a module that needs none; and **it still does not close the hole**, because §A1.6 froze `user_id` nullable so an external signatory without an account must remain recordable — the null path survives any amount of search UI. Named as the **durable close in a later phase**: if a directory-backed signer picker arrives (Phase 3's workshop capture is its natural home), `user_id` becomes populated for internal signers and the actor fallback narrows to the genuinely external case. That is an amendment naming the phase, not a Phase 1 route.

*Required tests.* (a) An author posts the UI shape — no `user_id` — with `is_independent_challenge: true`: flag stored `false`, warning flashed, and a following `validated → signed_off` refused by C1.2. (b) `SignoffRulesTest.php:287` unchanged: a non-author actor scribing an external challenge stores `true`, no warning. (c) An author posts an explicit `user_id` naming a *different* user who claims independence: flag stored `true` — the signer is not the author, and the actor is only the scribe.

**C2.2 — F-2 GUARD. `storeSignoffs` gains a status guard, as a behaviour clarification.**

*Verified.* `ScenarioGovernanceController::storeNotes()` (`:127+`) refuses with a flash when `status === 'archived'`. `storeSignoffs()` (`:88-110`) has **no** status guard, and passes `review_due_on` into `recordSignoffs()`, which writes it at `ScenarioLifecycle.php:427-441` without ever reaching `ScenarioService::refuseIfReadOnly()` (`:220-232`). A frozen scenario can therefore still gain sign-off rows and a new review date.

*Correction.* `storeSignoffs` refuses with a **flash, not a 403** (standard §3) when `$scenario->isFrozen()` (`Scenario.php:400-403`, over `FROZEN_STATUSES = ['signed_off','archived']` at `:155`) **or** when `signed_off` is not reachable from the current status per `STATUS_TRANSITIONS`. The `review_due_on` write on that path goes through the **same** refusal: it is part of one act, and a review date landing on a frozen record breaks spec 01 §5.5's "the figures it carries are the ones recorded at sign-off" from the side nobody watches. Grounds: D4 (sign-off freezes), Addendum-4 (`archived` terminal), spec 01 §5.5's table.

**The guard is `isFrozen() || ! in_array('signed_off', STATUS_TRANSITIONS[$status], true)` — NOT `refuseIfReadOnly()`.** That distinction is the whole content of this item. `refuseIfReadOnly()` treats `validated` as read-only and answers "Return it for rework to change it"; reusing it verbatim would refuse the **legitimate** `validated → signed_off` path and break sign-off outright. Sign-off is the one write a read-only status permits, by design.

This also closes D-1's draft-status repro: from `draft`, `signed_off` is unreachable, so the write is refused before any marker question arises. *Refused:* the guard in the service only. The service keeps its own check so a Phase 5 import or a direct call meets it — but the **message** belongs in the controller, because a `ScenarioRefused` thrown inside `recordSignoffs`' `DB::transaction` rolls back correctly and then surfaces as whatever the exception handler decides, which is not a sentence a signer can act on. Both, not either.

**C2.3 — D-1/F-3 BANNER EVIDENCE. SANCTIONED, with four conditions.**

*The defect, verified.* `ScenarioLifecycle::previousSignoff()` (`:594-619`) derives the marker from "not frozen" plus "≥1 approved sign-off row with a `signed_at`". Status alone cannot prove the scenario ever **reached** `signed_off` — no status is uniquely post-sign-off, and a merely-`validated` scenario with recorded sign-offs satisfies the current test. So `Workspace.jsx:287-293` renders spec 01 §2's "Signed off {date} by {names}; reopened for revalidation" for a scenario that was never signed off: a false statement about approval history, on the one screen a validator re-reads.

*Sanctioned:* an audit-trail read on the render path, for this one marker — `risk_audit_trail` where `entity_type` ∈ `MorphTypes::spellingsFor('sm_scenario')`, `entity_id` = the scenario, `action_type = 'status_changed'`, `new_value = 'signed_off'`, as an `exists()`.

*Why it is sound, verified not assumed.* `AuditTrailService::record($scenario, 'status_changed', 'status', $from, $to)` is called **inside the same `DB::transaction`** as the transition (`ScenarioLifecycle.php:326`; the transaction opens at `:295`), so the row and the status cannot disagree — a rolled-back transition leaves neither. `AuditTrailService` contains **no try/catch** (verified: no `Throwable` in the file), so unlike BCMS's `BcmsAuditable::writeBcmsAuditRow()` — the deliberate swallow ADR 0014 was written about — a failed audit write here fails the transition rather than silently omitting the evidence the marker depends on. The lookup uses `risk_audit_trail`'s `index(['entity_type','entity_id'])` (`2026_02_22_200016:27`) on its leading columns: one indexed `exists()`, not a scan.

*Conditions on the sanction.*
1. It stays in `ScenarioLifecycle::previousSignoff()` (or a named method beside it), **never inline in the presenter or JSX** — standard §1, and it keeps the rule reachable by a test with no HTTP.
2. `entity_type` is matched through **`MorphTypes::spellingsFor('sm_scenario')`**, not a string literal. `AuditTrailMorphSpellingTest` exists because historic rows were written in three spellings; a literal is a marker that silently stops rendering the day an alias is corrected.
3. **Fail closed.** No matching row → no marker, and never a fallback to the sign-off-row heuristic, which is the false positive being removed. If the audit trail is pruned or archived, the marker goes quiet — an omission, not a lie.
4. The existing `isFrozen()` early return **stays**: spec 01 §2 scopes the marker to a scenario that left `signed_off` and is now editable, so it must not render on a currently `signed_off` record.

*The ADR 0026 tension, recorded because it is why this needs sanctioning at all.* ADR 0026's Consequences state the house preference plainly: history "can be answered from rows. None of it comes from logs." This is a **deliberate, narrow exception**, and the reason is that §A1.1 froze the Phase 1 table set at five and refuses a sixth — so within the frozen schema **no domain row records "reached `signed_off`"**. The choices were a false statement on a governance screen, a sixth table, or one indexed audit read. *Durable fix, named:* if a later phase adds a status-history table (an amendment naming that phase, outside plan §4.2's list), the marker moves to it and this sanction is retired.

*Refused — the fallback gate 2 offered:* narrowing the copy to "Approved by {sources} on {date}". It is truthful, and that is exactly why it is worse: spec 01 §2 says the marker "is the only place the earlier sign-off survives on screen, and it must survive", because a validator re-reading a reopened scenario needs to know it was once **signed off**, not merely that approvals exist. "Approved by" is also true of a scenario that never left `validated`, so on the very rows where the distinction matters it carries no information. It remains the fallback of last resort if the audit trail is pruned, and spec 01 §2's wording change would be recorded then.

**C2.4 — D-3 RATIFIED. `scenario.risk-search` is Phase 1's twelfth route.**

Ratified, verified against the code: `routes/web.php:2974-2975`, `GET risk-search` under the `scenario.` name prefix with `permission:scenario.view`; required by spec 02 §5.7. `SearchRisksRequest::authorize()` returns exactly `$this->user()?->can('scenario.view') === true` — an **exact match** to the route middleware, so the Form Request and the route cannot drift; `MINIMUM_TERM = 2` and `RESULT_CAP = 20` are consts on that request, not literals at the call site; and `ScenarioController::riskSearch()` (`:255+`) narrows through `Risk::visibleTo($user)` and nulls `url` for a caller without `risk.view`, so the type-ahead leaks nothing the caller may not already open. **§A1.11's route list is amended from eleven routes to twelve**, recorded here so a future ADR-vs-`route:list` diff finds no unsanctioned route.

*One observation, recorded not reopened:* the shipped transition stamps `validated_on` when it is null (`ScenarioLifecycle.php:306-308`), while §A1.2 froze it as a user-entered date. It only fills a null, so a typed date survives, and a validation date nobody typed is still a true one — accepted as shipped. `designed_on` correctly remains unstamped, which is the half of that freeze the workbook actually cares about.

## HANDOFF

**Correction 2 freezes four things and changes no schema:** C1.5 re-keyed to the acting user when `user_id` is null (F-1 blocker — the `SignoffRulesTest.php:287` pass-through preserved, three tests required); a reachability-based status guard on `storeSignoffs` covering the `review_due_on` write (F-2, explicitly **not** `refuseIfReadOnly()`); a sanctioned, four-conditioned, fail-closed `risk_audit_trail` read for the "Previously signed off" marker, with the ADR 0026 exception recorded and its durable fix named (D-1/F-3); and `scenario.risk-search` ratified as the twelfth Phase 1 route (D-3).
**Consumers:** backend-engineer (three code changes, no migration) · qa-engineer (the three C2.1 tests, a frozen-status sign-off refusal, and a marker test covering validated-but-never-signed-off) · ui-designer (no spec change — the marker copy stands as written in spec 01 §2) · code-reviewer (gate 2 re-run; the C1.5 actor-fallback and the reachability guard are the two veto items to re-check).

---

## Amendment 2 (2026-11-13) — Phase 2 Column-Level Schema Freeze & Quantitative Computation Contract

**Status:** Accepted · **Phase:** Scenario Modelling & Analysis, Phase 2 (Quantitative Engine) · **Author:** architect  
**Requested by:** Mohammed, 2026-11-13 — "Proceed with phase 2"  
**Consumers:** backend-engineer (migrations, models, `ScenarioComputationService`) · ui-designer (screen specs 06–09) · frontend-engineer (workspace tabs) · qa-engineer (golden-value characterisation fixtures) · code-reviewer (Gate 2)

---

### A2.1 Boundary: Six Phase 2 Tables

Phase 2 creates exactly six tables, fulfilling plan §4.2 and Amendment 1 §A1.1/§A1.13:
1. `sm_scorecards` — control environment nature, total weights and computed global score.
2. `sm_scorecard_components` — COSO 5 components (efficiency level 1–5, weighting 1–5, scores).
3. `sm_frequency_drivers` — driver rows (count, scorecard complement, probability, data source).
4. `sm_impact_types` — impact categories (name, aggregation template, transferable flag, subtotals).
5. `sm_impact_criteria` — criteria rows per impact type (role, unit, typical/serious/extreme values).
6. `sm_insurance_coverages` — per-severity risk-transfer netting rows (transferable, deductibles, limits, haircuts, net impact).

**Refused in Phase 2:**
- Variant deck tables (`sm_scenario_variants`, `sm_variant_sections`, `sm_variant_owners`, `sm_scenario_actions`) remain Phase 3.
- Quantification bridge tables and `QuantificationScenario` export remain Phase 4.
- Any computed summary column on `sm_scenarios` (reaffirming Amendment 1 §A1.13: computed figures live strictly on the Phase 2 child tables).

---

### A2.2 `sm_scorecards` & `sm_scorecard_components`

One scorecard per scenario (1:1), holding the control environment nature and aggregate score.

#### `sm_scorecards`
| # | Column | Type | Null | Default | Description |
|---|---|---|---|---|---|
| 1 | `id` | `id()` | no | auto | Primary key |
| 2 | `organization_id` | `foreignId()->constrained()->cascadeOnDelete()` | no | — | Tenant isolation |
| 3 | `sm_scenario_id` | `foreignId()->constrained('sm_scenarios')->cascadeOnDelete()` | no | — | 1:1 with scenario |
| 4 | `control_nature` | `string(20)` | no | `'mixed'` | `mainly_manual` \| `mixed` \| `mainly_automated` |
| 5 | `total_weight` | `unsignedSmallInteger` | yes | NULL | Sum of component weights ($\Sigma K$) |
| 6 | `total_weighted_score` | `decimal(12,4)` | yes | NULL | Sum of component weighted scores ($\Sigma L$) |
| 7 | `global_score` | `decimal(12,8)` | yes | NULL | $\Sigma L / \Sigma K$ (0.0 to 1.0) |
| 8 | `created_by` | `foreignId()->constrained('users')->nullOnDelete()` | yes | NULL | Creator |
| 9 | `updated_by` | `foreignId()->constrained('users')->nullOnDelete()` | yes | NULL | Last editor |
| 10 | `created_at`, `updated_at` | `timestamps()` | yes | NULL | Standard audit timestamps |

**Indexes:** unique `sm_scorecards_scenario_unique (sm_scenario_id)`; index `sm_scorecards_org_scenario_idx (organization_id, sm_scenario_id)`.

#### `sm_scorecard_components`
| # | Column | Type | Null | Default | Description |
|---|---|---|---|---|---|
| 1 | `id` | `id()` | no | auto | Primary key |
| 2 | `organization_id` | `foreignId()->constrained()->cascadeOnDelete()` | no | — | Tenant isolation |
| 3 | `sm_scorecard_id` | `foreignId()->constrained('sm_scorecards')->cascadeOnDelete()` | no | — | Owning scorecard |
| 4 | `component_code` | `string(40)` | no | — | One of the 5 COSO codes (§A2.6) |
| 5 | `efficiency_level` | `unsignedTinyInteger` | no | `0` | 0 (unset) or 1..5 |
| 6 | `weighting_factor` | `unsignedTinyInteger` | no | `0` | 0 (unset) or 1..5 |
| 7 | `score` | `unsignedTinyInteger` | no | `0` | Matches `efficiency_level` (0..5) |
| 8 | `level_score` | `decimal(5,2)` | no | `0.00` | Non-linear table score `{1:0.20, 2:0.40, 3:0.55, 4:0.70, 5:0.90}` |
| 9 | `adjusted_score` | `decimal(5,2)` | no | `0.00` | `level_score - deduction` (`manual`: -0.10, `mixed`: -0.05, `automated`: 0.00) |
| 10 | `weighting_score` | `unsignedTinyInteger` | no | `0` | Matches `weighting_factor` (0..5) |
| 11 | `weighted_score` | `decimal(8,4)` | no | `0.0000` | `adjusted_score * weighting_score` |
| 12 | `notes` | `text` | yes | NULL | Component evidence / notes |
| 13 | `sort_order` | `unsignedSmallInteger` | no | `0` | Order (1..5) |
| 14 | `created_at`, `updated_at` | `timestamps()` | yes | NULL | Timestamps |

**Indexes:** unique `sm_sc_components_scorecard_code_unique (sm_scorecard_id, component_code)`; index `sm_sc_components_org_scorecard_idx (organization_id, sm_scorecard_id)`.

---

### A2.3 `sm_frequency_drivers`

Stores input rows feeding the expected annual frequency calculation.

| # | Column | Type | Null | Default | Description |
|---|---|---|---|---|---|
| 1 | `id` | `id()` | no | auto | Primary key |
| 2 | `organization_id` | `foreignId()->constrained()->cascadeOnDelete()` | no | — | Tenant isolation |
| 3 | `sm_scenario_id` | `foreignId()->constrained('sm_scenarios')->cascadeOnDelete()` | no | — | Owning scenario |
| 4 | `driver_type` | `string(50)` | no | — | Driver semantic key (§A2.6) |
| 5 | `criterion` | `string(250)` | no | — | Descriptive label (e.g. "Exposed Staff count") |
| 6 | `computation_role` | `string(30)` | no | — | `count` \| `control_score_complement` \| `probability` |
| 7 | `value` | `decimal(18,8)` | no | `0.00000000` | Numerical driver value |
| 8 | `data_source` | `string(100)` | no | `'expert_judgement'` | Provenance text (HR info, Scorecard, etc.) |
| 9 | `sm_scorecard_id` | `foreignId()->constrained('sm_scorecards')->nullOnDelete()` | yes | NULL | Optional scorecard link |
| 10 | `sort_order` | `unsignedSmallInteger` | no | `0` | Display order |
| 11 | `created_at`, `updated_at` | `timestamps()` | yes | NULL | Timestamps |

**Indexes:** index `sm_freq_drivers_org_scenario_idx (organization_id, sm_scenario_id)`.

---

### A2.4 `sm_impact_types` & `sm_impact_criteria`

Hierarchical impact decomposition. Each scenario has 1..N impact types, each containing 1..N criteria across Typical, Serious, and Extreme severities.

#### `sm_impact_types`
| # | Column | Type | Null | Default | Description |
|---|---|---|---|---|---|
| 1 | `id` | `id()` | no | auto | Primary key |
| 2 | `organization_id` | `foreignId()->constrained()->cascadeOnDelete()` | no | — | Tenant isolation |
| 3 | `sm_scenario_id` | `foreignId()->constrained('sm_scenarios')->cascadeOnDelete()` | no | — | Owning scenario |
| 4 | `name` | `string(250)` | no | — | Impact category name |
| 5 | `aggregation_template` | `string(50)` | no | `'staff_turnover_interval'` | `staff_turnover_interval` \| `litigation_probability` (§A2.6) |
| 6 | `eligible_to_transfer` | `boolean` | no | `false` | Transferable to insurance |
| 7 | `sort_order` | `unsignedSmallInteger` | no | `0` | Display order |
| 8 | `typical_total` | `decimal(18,8)` | no | `0.00000000` | Computed Typical subtotal |
| 9 | `serious_total` | `decimal(18,8)` | no | `0.00000000` | Computed Serious subtotal |
| 10 | `extreme_total` | `decimal(18,8)` | no | `0.00000000` | Computed Extreme subtotal |
| 11 | `created_at`, `updated_at` | `timestamps()` | yes | NULL | Timestamps |

**Indexes:** index `sm_impact_types_org_scenario_idx (organization_id, sm_scenario_id)`.

#### `sm_impact_criteria`
| # | Column | Type | Null | Default | Description |
|---|---|---|---|---|---|
| 1 | `id` | `id()` | no | auto | Primary key |
| 2 | `organization_id` | `foreignId()->constrained()->cascadeOnDelete()` | no | — | Tenant isolation |
| 3 | `sm_impact_type_id` | `foreignId()->constrained('sm_impact_types')->cascadeOnDelete()` | no | — | Parent impact type |
| 4 | `label` | `string(250)` | no | — | Criterion label (e.g. "Monthly gross salary") |
| 5 | `unit` | `string(50)` | yes | NULL | Unit label (e.g. "count", "months", "NGN", "%") |
| 6 | `computation_role` | `string(30)` | no | — | `count` \| `unit_amount` \| `duration` \| `probability` \| `flat_fee` |
| 7 | `data_source` | `string(100)` | yes | NULL | Provenance source string |
| 8 | `typical_value` | `decimal(18,8)` | no | `0.00000000` | Typical input value |
| 9 | `serious_value` | `decimal(18,8)` | no | `0.00000000` | Serious input value |
| 10 | `extreme_value` | `decimal(18,8)` | no | `0.00000000` | Extreme input value |
| 11 | `sort_order` | `unsignedSmallInteger` | no | `0` | Order within type |
| 12 | `created_at`, `updated_at` | `timestamps()` | yes | NULL | Timestamps |

**Indexes:** index `sm_impact_crit_org_type_idx (organization_id, sm_impact_type_id)`.

---

### A2.5 `sm_insurance_coverages`

Stores insurance recovery netting rows for each of the three severities: `typical`, `serious`, and `extreme`.

| # | Column | Type | Null | Default | Description |
|---|---|---|---|---|---|
| 1 | `id` | `id()` | no | auto | Primary key |
| 2 | `organization_id` | `foreignId()->constrained()->cascadeOnDelete()` | no | — | Tenant isolation |
| 3 | `sm_scenario_id` | `foreignId()->constrained('sm_scenarios')->cascadeOnDelete()` | no | — | Owning scenario |
| 4 | `severity` | `string(20)` | no | — | `typical` \| `serious` \| `extreme` |
| 5 | `transferable_amount` | `decimal(18,8)` | no | `0.00000000` | Sum of transferable impact types |
| 6 | `deductible_local` | `decimal(18,8)` | no | `0.00000000` | Local policy deductible |
| 7 | `limit_local` | `decimal(18,8)` | no | `0.00000000` | Local policy limit |
| 8 | `deductible_group` | `decimal(18,8)` | no | `0.00000000` | Group policy deductible |
| 9 | `limit_group` | `decimal(18,8)` | no | `0.00000000` | Group policy limit |
| 10 | `recovery_rate` | `decimal(5,4)` | no | `1.0000` | Expected recovery (0.0 to 1.0) |
| 11 | `probability_of_cover` | `decimal(5,4)` | no | `1.0000` | Probability policy responds (0.0 to 1.0) |
| 12 | `insurer_default_haircut`| `decimal(5,4)` | no | `0.0000` | Insurer default risk haircut (0.0 to 1.0) |
| 13 | `residual_amount` | `decimal(18,8)` | no | `0.00000000` | Tiered retained amount |
| 14 | `total_haircut` | `decimal(5,4)` | no | `0.0000` | $1 - (\text{recovery} \times P) + \text{haircut}$ |
| 15 | `haircut_impact` | `decimal(18,8)` | no | `0.00000000` | $(\text{transferable} - \text{residual}) \times \text{total\_haircut}$ |
| 16 | `gross_impact` | `decimal(18,8)` | no | `0.00000000` | Total gross impact for this severity |
| 17 | `net_impact` | `decimal(18,8)` | no | `0.00000000` | $\text{residual} + \text{haircut\_impact} + \text{gross} - \text{transferable}$ |
| 18 | `created_at`, `updated_at` | `timestamps()` | yes | NULL | Timestamps |

**Indexes:** unique `sm_ins_coverages_scenario_sev_unique (sm_scenario_id, severity)`; index `sm_ins_coverages_org_scenario_idx (organization_id, sm_scenario_id)`.

---

### A2.6 Vocabularies & Calculation Specification

#### 1. Scorecard Vocabularies & Formulas
- `Scorecard::CONTROL_NATURES`: `mainly_manual` (Mainly manual), `mixed` (Mixed manual and automated), `mainly_automated` (Mainly automated).
- `ScorecardComponent::COMPONENTS`:
  1. `control_environment` (I. Control environment)
  2. `risk_assessment` (II. Risk assessment)
  3. `control_activities` (III. Control activities)
  4. `information_communication` (IV. Information & communication)
  5. `monitoring` (V. Monitoring)
- Non-linear Level Scores:
  $$H_i = \{1: 0.20, 2: 0.40, 3: 0.55, 4: 0.70, 5: 0.90\}[G_i]$$
  (0 or unset yields $0.00$).
- Environmental Nature Deduction:
  - `mainly_manual`: $-0.10$
  - `mixed`: $-0.05$
  - `mainly_automated`: $0.00$
- $I_i = \max(0, H_i - \text{deduction})$
- $L_i = I_i \times K_i$ (where $K_i$ is `weighting_factor` $1..5$)
- Global Score:
  $$\text{Global} = \frac{\sum L_i}{\sum K_i}$$
  Golden verification: K=22, L=15.2 $\rightarrow$ Global Score = `0.6909090909090909`.

#### 2. Frequency Formulas
- Frequency driver computation roles: `count`, `control_score_complement`, `probability`.
- Expected Annual Frequency ($\lambda$):
  $$\lambda = \text{Count} \times (1 - \text{ScorecardScore}) \times \text{Probability}$$
  Golden verification: $55 \times (1 - 0.6909090909090909) \times 0.95 = 16.15$ events/year.
- Frequency Interval:
  $$\text{Interval} = \frac{1}{\lambda} \text{ years}$$
  Golden verification: $1 / 16.15 = 0.061919504643962855$ years.

#### 3. Impact Templates & Gross Total
- `ImpactType::TEMPLATES`:
  1. `staff_turnover_interval`: $\text{count} \times \text{amount} \times \text{duration} \times \text{interval}$  
     Golden: Typical = `14775676.78018576`, Serious = `22163515.17027864`, Extreme = `44327030.34055728`.
  2. `litigation_probability`: $(\text{count} \times \text{amount} + \text{flat\_fee}) \times \text{probability}$  
     Golden: Typical = `348283.975`, Serious = `696567.95`, Extreme = `1044851.925`.
- Gross Impact per severity:
  $$\text{Gross}(\text{sev}) = \sum \text{TypeTotal}(\text{sev})$$
  Golden: Typical = `15123960.75518576`, Serious = `22860083.120278638`, Extreme = `45371882.265557274`.

#### 4. Insurance Netting & Net Impact
- $\text{Transferable}(\text{sev}) = \sum_{\text{eligible}} \text{TypeTotal}(\text{sev})$.
- Tiered Residual $t = \text{Transferable}(\text{sev})$:
  - If $t < \text{dedL} \rightarrow t$
  - Else if $t < \text{limL} \rightarrow \text{dedL}$
  - Else if $t < \text{dedG} \rightarrow t - \text{limL} + \text{dedL}$
  - Else if $t < \text{limG} \rightarrow \text{dedG} - \text{limL} + \text{dedL}$
  - Else $\rightarrow t - \text{limG} + \text{dedG} - \text{limL} + \text{dedL}$
- Haircut:
  $$\text{TotalHaircut} = 1 - (\text{recovery\_rate} \times \text{probability\_of\_cover}) + \text{default\_haircut}$$
  $$\text{HaircutImpact} = (\text{Transferable} - \text{Residual}) \times \text{TotalHaircut}$$
- Net Impact:
  $$\text{Net} = \text{Residual} + \text{HaircutImpact} + \text{Gross} - \text{Transferable}$$
  Golden (uninsured case, transferable = 0): Net equals Gross exactly across all severities.

---

### A2.7 Authorization, Routes & Lifecycle

- **Permission:** `scenario.design` (already declared in `RiskPermissionCatalog`).
- **Routes:** 
  - `POST /scenarios/{scenario}/scorecard` (`scenario.scorecard.update`)
  - `POST /scenarios/{scenario}/frequency` (`scenario.frequency.update`)
  - `POST /scenarios/{scenario}/impact` (`scenario.impact.update`)
  - `POST /scenarios/{scenario}/insurance` (`scenario.insurance.update`)
- **Lifecycle Invariants:**
  - Quantitative edits are refused with flash error when scenario is read-only (`status` is `signed_off`, `archived`, or `in_validation`).
  - At `in_design -> designed` transition: frequency ($\lambda > 0$) and impact (at least 1 impact type with computed totals) must be present.

---

## HANDOFF
- **Role:** architect
- **Task:** ADR 0027 Amendment 2 — Phase 2 Column-Level Schema Freeze & Quantitative Contract
- **Verdict:** Accepted and Frozen
- **Deliverables:**
  - 6 tables defined with exact columns, types, nullability, defaults, and foreign keys.
  - Calculation contract frozen matching decoded BIFF8 formulas and spike golden totals.
  - Invariants, authorization, and route shapes settled.
- **Next steps:** Create `plans/scenario-modelling/PHASE2-GATE-CHECKLIST.md`, followed by UI screen specs and backend implementation.

---

## Amendment 3 (2026-11-13) — Phase 3 Column-Level Schema Freeze & Variant Decks / Actions Contract

**Status:** Accepted · **Phase:** Scenario Modelling & Analysis, Phase 3 (Variant Decks & Actions) · **Author:** architect  
**Requested by:** Mohammed, 2026-11-13 — "proceed"  
**Consumers:** backend-engineer (migrations, models, services) · ui-designer (screen specs 10–13) · frontend-engineer (workspace tabs, deck view, action tracker) · qa-engineer · code-reviewer (Gate 2)

---

### A3.1 Boundary: Phase 3 Tables and Columns

Phase 3 creates exactly two new tables and applies column additions deferred in Amendment 1 §A1.13:

1. `sm_scenario_variants` — escalating severe-but-plausible scenario tiers (Ebola reference deck S1/S2/S3).
2. `sm_scenario_actions` — gap remediation actions and immediate plan items with enterprise Issue promotion.
3. Column additions to existing tables:
   - `sm_scenarios.ai_generated`: boolean default `false`.
   - `sm_scenario_notes.is_ai_generated`: boolean default `false`.
   - `sm_scenario_signoffs.requested_at`: dateTime nullable.
   - `sm_scenario_signoffs.requested_by`: foreignId users nullable constrained nullOnDelete.

---

### A3.2 Table Schema: `sm_scenario_variants`

One row per escalating severity tier for a scenario theme (e.g., Tier 1 Contained Outbreak, Tier 2 Regional Transmission & BCP Activation, Tier 3 Nationwide Pandemic & Crisis Management).

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | `bigIncrements` | No | — | PK |
| `organization_id` | `foreignId` | No | — | `constrained('organizations')->cascadeOnDelete()` |
| `sm_scenario_id` | `foreignId` | No | — | `constrained('sm_scenarios')->cascadeOnDelete()` |
| `tier` | `string(20)` | No | — | `tier_1`, `tier_2`, `tier_3` (matches Low/Medium/High severity scale) |
| `name` | `string(150)` | No | — | Display label, e.g. "Scenario 1: Contained Outbreak" |
| `narrative` | `text` | Yes | NULL | Narrative description of the variant conditions |
| `is_cumulative` | `boolean` | No | `false` | True if actions/responses build on earlier tiers ("In addition to Scenario 1...") |
| `triggers` | `json` | Yes | NULL | Array of bulleted trigger thresholds/conditions |
| `operational_impacts` | `json` | Yes | NULL | Array of bulleted operational impacts |
| `responses` | `json` | Yes | NULL | Array of bulleted response measures |
| `response_owners` | `json` | Yes | NULL | Array of owning units / departments / roles |
| `sort_order` | `unsignedTinyInteger` | No | `1` | 1..3 display order |
| `timestamps` | `nullableTimestamps` | Yes | NULL | Standard §13 |

**Keys & Indexes:**
- Unique: `sm_variants_scenario_tier_unique (sm_scenario_id, tier)`
- Index: `sm_variants_org_scenario_idx (organization_id, sm_scenario_id)`

---

### A3.3 Table Schema: `sm_scenario_actions`

Stores both gap remediation actions (Possible Risk & Resolution Plan, Ebola deck slides 7–8) and immediate plan action items (Ebola deck slides 10–11).

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | `bigIncrements` | No | — | PK |
| `organization_id` | `foreignId` | No | — | `constrained('organizations')->cascadeOnDelete()` |
| `sm_scenario_id` | `foreignId` | No | — | `constrained('sm_scenarios')->cascadeOnDelete()` |
| `kind` | `string(30)` | No | — | `gap_remediation` or `immediate` |
| `time_horizon` | `string(50)` | Yes | NULL | Immediate plan horizon: `today`, `within_7_days`, `within_30_days`, `custom` |
| `risk_area` | `string(200)` | No | — | Risk category or area (e.g. "Operational disruption", "Cybersecurity risk") |
| `current_position` | `text` | Yes | NULL | Where we are / documented controls |
| `identified_gap` | `text` | Yes | NULL | What we ought to have but don't have |
| `action_plan` | `text` | No | — | Where we ought to be / action point |
| `business_unit_id` | `foreignId` | Yes | NULL | `constrained('business_units')->nullOnDelete()` |
| `department` | `string(200)` | Yes | NULL | Department / function text snapshot |
| `owner_id` | `foreignId` | Yes | NULL | `constrained('users')->nullOnDelete()` |
| `due_date` | `date` | Yes | NULL | Target completion date |
| `status` | `string(30)` | No | `'not_started'` | `not_started`, `in_progress`, `completed`, `overdue` |
| `completed_at` | `dateTime` | Yes | NULL | Stamp when marked completed |
| `proof_of_closure` | `text` | Yes | NULL | Summary of evidence / verification note |
| `issue_id` | `foreignId` | Yes | NULL | `constrained('issues')->nullOnDelete()` — enterprise issue link |
| `sort_order` | `unsignedInteger` | No | `1` | Order within tab |
| `timestamps` | `nullableTimestamps` | Yes | NULL | Standard §13 |

**Keys & Indexes:**
- Index: `sm_actions_org_status_idx (organization_id, status)`
- Index: `sm_actions_scenario_kind_idx (sm_scenario_id, kind)`
- Index: `sm_actions_issue_idx (issue_id)`

---

### A3.4 Enterprise Issue Promotion Contract

When a scenario gap remediation or immediate action is promoted to enterprise tracking:
1. An `Issue` row is created in `issues` with:
   - `organization_id` matching the action's organization.
   - `issue_reference` generated using the Issue module reference sequence.
   - `title`: `{$action->risk_area}: ` + truncated `action_plan`.
   - `description`: Scenario attribution, current position, identified gap, and action plan.
   - `issue_source`: `'SCENARIO_ANALYSIS'`.
   - `issue_category`: `'OPERATIONAL_RESILIENCE'`.
   - `priority`: `'HIGH'`.
   - `issue_status`: `'OPEN'`.
   - `business_unit_id`: `$action->business_unit_id`.
   - `department`: `$action->department`.
   - `responsible_owner_id`: `$action->owner_id`.
   - `remediation_due_date`: `$action->due_date`.
   - `action_plan`: `$action->action_plan`.
   - `created_by`: `$actor->id`.
2. An initial `issue_remediation_actions` row is created linked to the issue.
3. `sm_scenario_actions.issue_id` is updated with the new issue's ID.
4. An audit trail event `scenario_action_promoted` is recorded.
5. If the action was already promoted (`issue_id` NOT NULL), re-promotion is refused.

---

### A3.5 Authorization & Route Endpoints

- **Permissions:**
  - `scenario.view`: View variants, actions, deck view, action tracker.
  - `scenario.update`: Edit scenario variants (`scenario.variants.update`).
  - `scenario.manage_actions`: Create, edit, delete scenario actions and promote to enterprise Issue.
- **Routes:**
  - `GET /scenarios/actions` (`scenario.actions.index`) — Action Tracker across scenarios.
  - `GET /scenarios/{scenario}/deck` (`scenario.deck`) — Executive Presentation Deck View.
  - `PUT /scenarios/{scenario}/variants` (`scenario.variants.update`) — Save variants deck.
  - `POST /scenarios/{scenario}/actions` (`scenario.actions.store`) — Create scenario action.
  - `PUT /scenarios/{scenario}/actions/{action}` (`scenario.actions.update`) — Update scenario action.
  - `DELETE /scenarios/{scenario}/actions/{action}` (`scenario.actions.destroy`) — Delete scenario action.
  - `POST /scenarios/{scenario}/actions/{action}/promote` (`scenario.actions.promote`) — Promote to enterprise Issue.

---

## HANDOFF
- **Role:** architect
- **Task:** ADR 0027 Amendment 3 — Phase 3 Column-Level Schema Freeze & Variant Decks / Actions Contract
- **Verdict:** Accepted and Frozen
- **Deliverables:**
  - 2 new tables (`sm_scenario_variants`, `sm_scenario_actions`) defined with exact columns, types, nullability, defaults, and foreign keys.
  - Column additions to `sm_scenarios`, `sm_scenario_notes`, `sm_scenario_signoffs` frozen.
  - Enterprise Issue promotion contract specified.
  - Authorization, routes, and invariants settled.
- **Next steps:** Create UI screen specs 10–13 and Phase 3 gate checklist.
