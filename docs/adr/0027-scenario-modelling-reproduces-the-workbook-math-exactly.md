# ADR 0027 — Scenario Modelling reproduces the LS workbook's math exactly, and the deck's tiers are rows, not documents

**Status:** Proposed · **Date:** 2026-11-10 · **Phase:** Scenario Modelling & Analysis, Phase 0 (`plans/scenario-modelling/SCENARIO-MODELLING-PLAN.md` v1.0, approved 2026-11-10) · **Author:** architect (drafted in-session; schema freeze to be re-confirmed at Phase 1 kickoff)
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
