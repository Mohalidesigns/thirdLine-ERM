# ADR 0021 — Phase 11 gets no new tables: 9.2 is evidenced by reference, and the KRI register is adopted the way TPRM already adopts it

**Status:** Accepted · **Date:** 2026-09-13 · **Phase:** BCMS Phase 11 (Training, reporting & compliance) · **Author:** architect
**Requested by:** compliance-analyst, `docs/bcms/phase-11-spec.md` §7 gaps 1–5
**Consumers:** backend-engineer **(lead)** · ui-designer · frontend-engineer · compliance-analyst · qa-engineer · code-reviewer · Phase 6 (a remediation lands here) · Phase 12

## Context

Phase 11 assembles evidence. Its schema question is therefore the opposite of
Phases 9 and 10's: not "where does this artefact live" but "what may this phase
be allowed to invent in order to make a matrix go green". Three requests, and one
of them is a defect nobody had found.

Verified in the code, not taken on report:

- **No audit programme exists anywhere.** No `bcms_audit_*`, no
  `audit_programmes`, no `audit_plan`, no audit engagement table in `app/`,
  `database/` or `packages/`. `FindingSource::Audit` carries no FK;
  `bcms_findings.erm_issue_id` → `issues` is the only audit-adjacent edge.
- **Nothing registers a BCMS KRI**, and `TreeHealthService::mirrorKris()` skips
  any `kri_code` the risk team has not defined — deliberately, with a good
  docblock reason. So Phase 6 computes four call-tree KRIs and files none.
- **TPRM has already solved this exact problem, twice over.**
  `App\Support\Tprm\KriCatalogue` holds nine definitions with shipped default
  thresholds, and `KriPublisher::adopt()` creates any missing KRI for the tenant,
  links it, and thereafter writes **only measurements** — "a republish that reset
  a threshold somebody had tuned would be the module overruling its own user once
  a day, silently". Readings go through `KriMeasureBridge::recordMeasurement()`,
  never by setting `current_value`, because the bridge resolves the period, writes
  the measure value, mirrors the legacy tables and reconciles the breach, in that
  order.
- **`bcms_training_records.certificate_id`** is the third unconstrained
  `unsignedBigInteger` pointing at no table, after Phase 9's two.
- **`IsoClauseRef`'s own docblock already rules on the certificate question:** an
  artefact claiming a mandatory record "must be a first-class row with an actor, a
  timestamp and an immutable state, **never an uploaded attachment**".

## Decision

### 1. ISO 22301 9.2 gets no tables. It is evidenced by reference, and the matrix says so.

Option (b), with two zero-migration improvements that make it better than a
refusal.

**Why not the two thin tables.** They are the most defensible-looking request in
the phase and they are still wrong here, for four reasons in descending order of
weight:

1. **An internal audit programme is an enterprise assurance object, not a
   continuity object.** The bank's internal audit function audits the credit
   book, the AML programme and the core banking platform as well as the BCMS. A
   `bcms_audit_programmes` table claims a suite-wide concept for one module, and
   the next module that wants it — RCSA, TPRM, the risk register — must either
   reach across a module boundary or build a second one. That is the exact
   failure the reuse rule exists to prevent, arriving one phase early.
2. **Independence is the substance of 9.2, and it is the one thing the audited
   module cannot assert about itself.** A row created by the BCMS admin, in the
   BCMS module, declaring that the BCMS audit was independent, is the weakest
   available evidence of independence. An auditor asks for the audit function's
   own working papers and their reporting line; a self-declared field in the
   audited system does not substitute for either.
3. **It is not two tables.** It is two tables, a screen, a permission pair, a
   seeder, a pack section and a sufficiency rule, in the phase whose whole value
   is assembling what already exists. This is the "while we're here" shape.
4. **Honest red is not a hole in the pack — it is the pack working.** The module
   already reports channels as mocked, rates over nothing as undefined and KRIs as
   null rather than zero. A matrix that says *"no internal audit programme is held
   in this system; ISO 22301 9.2 is evidenced from the internal audit function's
   own records"* is more use to a bank than a green cell over a form somebody
   filled in once.

**The two improvements, both zero-migration:**

- **9.2 results are already better than amber.** `bcms_findings` with
  `source = audit` plus `erm_issue_id` → `issues` means an audit finding raised in
  the bank's own workflow can be linked, carry its failed clause ref and appear in
  the CAPA chain. The cell reports the findings and links the `issues` rows, and
  reads amber only when nothing is linked.
- **The audit report is captured as a management-review input.**
  `bcms_management_reviews.inputs` is a json snapshot written by
  `captureReviewInputs()`, so an `internal_audit` block — report reference, date,
  auditor, independence statement text, conclusion — is a **service** change, not
  a schema one. Clause 9.3.2 requires audit results as a review input anyway, so
  this closes the analyst's §1.2 snapshot gap and gives 9.2 a minuted, approved,
  approver-signed home in the same edit. Where that block is present the 9.2
  programme cell renders **amber with the reference**; where it is absent, **red
  with the sentence above**.

**The matrix therefore has four states, not three** — green with artefacts, amber
with an external reference or partial evidence, red with the missing artefact
named, grey with the not-applicable rationale from `bcms_programme_obligations`.
The ui-designer specs four. **9.2 is never grey**: it applies to every certified
BCMS, and grey would read as "not required".

**The escalation path, so this is not re-litigated per module.** If a design
partner's certification audit requires the programme inside the product, the
answer is a **platform-level** `audit_programmes` / `audit_engagements` pair owned
by the platform and readable by every module, with its own ADR and its own
permission set — not a BCMS table with a `bcms_` prefix. Whoever needs it raises
that ADR; Phase 11 does not pre-empt it.

### 2. KRI definitions are adopted by Phase 11. That is consistent with Orchestration §5, and there is a defect underneath it.

**Approved, with the split the spec proposes:** Phase 11 ships the seventeen
definitions and adopts them per tenant; **measurements stay with the producing
phase.**

**Why this does not breach §5's "the phase that produces the metric registers
it".** §5's rule exists to stop two modules maintaining two metric stores. It is
satisfied here: there is still exactly one metric store (`key_risk_indicators` +
the measure engine), still one writer per reading, and no `bcms_kri` or
`bcms_metric` table. What the rule never contemplated is that **the phase named
to publish the definitions (2C) was cut**, so on the current build nobody
registered anything and four computed KRIs are discarded daily. Reading §5 to
forbid Phase 11 from seeding definitions would enforce the letter of a
reuse rule by leaving the register empty — and criterion 4 unsatisfiable. The
substance of §5 is "one metric store, one writer per metric", and that holds.

**The precedent is TPRM's and it is followed, not re-invented:** a `Support`
catalogue of definitions with shipped default thresholds, an `adopt()` that
creates what is missing and links it, `updateOrCreate`-style idempotency on the
code, and **a republish that never overwrites a threshold, name or owner the
tenant has changed**. A bank that moved a target owns that decision.

**No `bcms_kri_links` table.** TPRM needed one because its internal metric `code`
(`critical_assessed_within_cadence`) and the register's `kri_code` (`TPRM-01`) are
two vocabularies. BCMS uses the `BCMS-*` code as the single identity on both
sides, so a link table would be a second name for one thing. The join is
`kri_code`.

**The defect that makes criterion 4 a trap.** `TreeHealthService::mirrorKris()`
writes `KriMeasurement::updateOrCreate(...)` **directly**. Even once the
definitions are seeded, that produces exactly what `KriPublisher`'s docblock warns
about: *"a KRI whose number moved and whose RAG band, breach record and history
did not."* The band, the breach and the measure-engine series all come from
`KriMeasureBridge::recordMeasurement()`. So Phase 11's work includes a **Phase 6
remediation**: `mirrorKris()` records through the bridge like TPRM's publisher
does. Without it, the four call-tree KRIs would file readings that never move a
band and never raise a breach, and the matrix would go green on a number no board
pack reflects. **A KRI that files a value and never breaches is worse than one
that files nothing**, because the first looks monitored.

Two further rules, both from the TPRM precedent:

- **A null reading is skipped and said out loud**, never published as zero.
  Publishing zero opens a red breach, notifies an owner and reaches a board pack
  from nothing.
- **An unlinked code is reported, not swallowed.** The current silent `continue`
  is how four KRIs vanished for two phases. The reporting screen shows "n of 17
  resilience KRIs are not linked", which is also the protection against a tenant
  editing a `kri_code`.

### 3. `bcms_training_records.certificate_id` is retired in place. No fifth evidence kind, no new table.

**No fifth `bcms_evidence` kind.** ADR 0019 §1 gave that table four owner kinds
*all of which reach an occurrence*, deliberately, so the model has exactly one
`orgAnchorPath()`. A certificate anchors on a user or a contact, which is a second
anchor path — precisely what ADR 0017 rule 2 calls a modelling question rather
than an `orWhere`. Adding it would undo the one property that makes evidence
visibility cheap and correct.

**No `bcms_training_evidence` table either, because clause 7.2 does not buy
one.** The analyst's own sufficiency rule is the argument: the 7.2 record is
`competency_assessed = true` with a non-null `assessor_id` and
`score >= pass_mark` — an actor, a date and a result. `IsoClauseRef`'s docblock
states the general form of this rule for every mandatory record: a first-class row
with an actor, a timestamp and an immutable state, **never an uploaded
attachment**. A provider's certificate PDF is the *provider's* assertion about
attendance; the bank's competence record is the assessed row. Storing the PDF
would add an artefact that is not the evidence, beside the row that is.

So `certificate_id` joins Phase 9's two and Phase 10's three: **nothing writes
it, the Form Requests stop accepting it, a guard test asserts no BCMS
`create()`/`update()`/`fill()` names it, and it stays in the manifest** until one
cleanup migration drops all six. Retiring in place and dropping are two
decisions, and only the first is needed in this phase.

**The condition on which this reopens**, stated so it is not argued from scratch:
if certificate files are genuinely required, the request is for a
**second-anchor design on `bcms_evidence`** (a nullable `occurrence_id` plus a
resolved anchor per kind, with the visibility rule worked out and ADR 0017
amended) — not a kind bolted onto the current one. That is an ADR, and it needs a
customer asking for it rather than a column implying it.

### 4. Confirmations, so gaps 4 and 5 are settled rather than assumed

- **CSAT pre-fill is a product decision, not a schema one — confirmed.** We do not
  hold the workbook, so cell coordinates are unknowable, and a guessed coordinate
  would put our guess **into a bank's regulatory submission** — the
  `NoFabricatedNumbersTest` family with a regulator on the receiving end. Ship a
  mapping content pack of `clause ref → CSAT section and question label`; the
  customer uploads their own workbook; the writer locates each question **by
  label**, writes our answer and artefact reference into the adjacent response
  cell, leaves what it cannot locate untouched, and **lists every unmatched
  question on a cover sheet**. Reuse RCSA's PhpSpreadsheet writers
  (`RcsaTemplateWriter`, `RcsaWorkbookWriter`, `RcsaImportProcessor`);
  `openpyxl` is Python and is not in this repository, whatever the blueprint says.
  Amend criterion 8 to match.
- **No `bcms_report_runs` table — confirmed.** A `bcms_audit_logs` entry with
  `event = pack.exported`, the actor and the parameters in `after` answers "is this
  the pack you gave the CBN in March", and the determinism rule in §3.1 is what
  makes the reprint identical. `pack.exported` is 14 characters, inside ADR 0014's
  60.
- **A vendor exercise participant is a `bcms_contacts` row carrying the vendor
  link — confirmed.** No `third_party_id` column on `bcms_exercise_participants`.
  `bcms_dependencies` with `dependable_type = 'tprm_third_party'` is already the
  vendor edge (ADR 0002's morph map), and a vendor's attendance at our drill is a
  person with a phone number, which is what a contact is.
- **Vendor continuity evidence is written to TPRM's tables through TPRM's own
  service and authority check**, never direct. `tp_bcp_tests`,
  `tp_awareness_attestations`, `tp_business_functions` and `tp_engagements`
  already hold everything the prompt asked BCMS to build for vendors. BCMS adds
  the dependency edge and reads.

## What this ADR deliberately does not do

- **It does not create `bcms_audit_programmes`, `bcms_audit_results`,
  `bcms_training_evidence`, `bcms_kri_links` or `bcms_report_runs`.** Five tables
  asked for across the spec; none approved.
- **It does not extend `bcms_evidence`.** ADR 0019's four kinds stand.
- **It does not add a clause ref.** Phase 11 publishes none; the taxonomy's last
  addition was Phase 10's `ndpa.breach_notification`, and no `dora.*` key is added
  to `export_packs` — the DORA pack is a crosswalk over existing ISO refs.
- **It does not write a second scorer or a second metric store.**
  `MaturityService` is the one scoring engine (Orchestration §5) and Phase 11
  builds the heatmap over it.
- **It does not decide where internal audit lives across the suite.** §1 names the
  escalation path and stops.

## Consequences

- **Freeze delta: 0 tables, 0 columns, 0 indexes. One column retired in place.**
  The line reads 15 (P1) → 8 (P2) → 5 (P3) → 1 (P4) → 0 (P5) → 1 (P6) → 0 (P7) →
  0 (P7.5) → 2C (3 tables, 1 column, 1 index) → P9 (1 table) → P10 (1 table, 1
  column, 1 alteration) → **P11 (nothing)**. The reporting phase asking for no
  schema is the discipline working, not a coincidence: everything it needs was
  specified before it was written.
- **Phase 11 carries a Phase 6 remediation.** `mirrorKris()` moves onto
  `KriMeasureBridge::recordMeasurement()`. It is small, it is outside Phase 11's
  nominal scope, and it is the difference between criterion 4 being met and
  appearing to be met. qa-engineer should test the **breach**, not the
  measurement row.
- **Two of eleven mandatory records stay non-green, by decision.** That is the
  number to put in front of the design partner, with the sentence, rather than a
  green matrix that would not survive the first certification audit. It is also a
  sales asset: a product that names the two records it does not hold is easier to
  trust about the nine it does.
- **The management-review snapshot grows and must be extended once.** The
  `internal_audit` block lands alongside the analyst's other §2.4 additions —
  incidents and lessons learned, audit results, previous-review actions,
  interested-party feedback, DR/call-tree/EMNS performance — in one change to
  `captureReviewInputs()`, because a snapshot extended twice produces two shapes
  of stored json that the pack then has to tolerate for ever.
- **Six dangling integer columns are now retired across three phases** (two in
  P9, three in P10, one here) and none is dropped. One cleanup migration, one
  release later, drops all six together; until then the guard test is what keeps
  them dead.

## Amendment 1 — criterion 5: branch scope is a property of drills and plans, not of the seventeen KRIs

**Status:** Accepted · **Date:** 2026-09-23 · **Author:** architect · **Raised by:** backend-engineer, `phase-11-notes.md` §13 defect 11
**Consumers:** backend-engineer **(lead)** · compliance-analyst (spec §6 text) · qa-engineer · code-reviewer · TPRM (shared `WidgetQueryEngine` helper)

**Context.** A BCMS-adopted KRI has `node_id` NULL for ever: `ObjectSourceMap`'s `key_risk_indicators`
spec reaches a node through `entity_id` or `risks.risk_id`, and `ResilienceKriPublisher::createKri()`
sets neither. The criterion test was written against KRIs, and that is the wrong target. The build
prompt's criterion 5 reads *"render on a branch org node, showing that branch's **drills and plan
status** only"*. It does not mention KRIs or maturity. Spec §6 kept it as "Unchanged", and the words
"resilience KRIs and maturity" appeared on the way to the test. Meanwhile `WidgetSourceRegistry` has
**no BCMS source at all**, so the criterion as written is not met for any object.

**Decision: option (a), narrowed rather than "met by readings".** The seventeen resilience KRIs are
**organisation-level by definition** and stay that way. A per-branch reading does not exist to lean
on: `KriMeasureBridge::recordMeasurement()` keys one value per KRI object per period, and nothing
in it carries a unit. Every producer (TreeHealthService, the vendor attestation and the rest)
computes a figure for the whole tenant. Maturity is the same (`bcms_maturity_assessments` has no
unit column; the heatmap already ships `per_branch_available: false`). Branch scope is met where
the data is branch-attributed: `bcms_plans.business_unit_id`, and an occurrence through
`definition_id` → `bcms_exercise_definitions.business_unit_id`.

| # | Change (no schema, no `ObjectSourceMap` change) |
|---|---|
| 1 | `WidgetQueryEngine`: extract the existing `$unitIds` subquery from `engagementsUnderNodes()` into `private unitsUnderNodes(array $nodeIds)`, then call it from there. TPRM's behaviour must be byte-identical. |
| 2 | `WidgetQueryEngine::applyScope()`: add two arms. `business_unit_ref`: `whereIn(column, unitsUnderNodes())`. `bcms_definition_units`: `whereIn(definition_id, select id from bcms_exercise_definitions where business_unit_id in unitsUnderNodes() and deleted_at is null)`. |
| 3 | `WidgetSourceRegistry::SOURCES`: add `bcms_plans` (`Plan`, `business_unit_id`/`business_unit_ref`, date `next_review_date`, permission `bcms.plan.view`) and `bcms_exercise_occurrences` (`ExerciseOccurrence`, `definition_id`/`bcms_definition_units`, date `scheduled_date`, permission `bcms.exercise.view`). Only columns that exist in `bcms-manifest.php` go on the whitelist. |
| 4 | New `Database\Seeders\Bcms\BcmsWidgetSeeder`, following the shape of `TprmWidgetSeeder` (system rows, `TenantContext::bypass`, idempotent on `code`), called from `DatabaseSeeder`. It ships a branch drill calendar (occurrences, upcoming), plan status (plans grouped by `status`), and **one** resilience-KRI tile whose name ends "(organisation-wide)" and whose description says it is not attributable to a branch. |
| 5 | `ResilienceKriPublisher::createKri()`: **no change**. Its docblock gains one sentence saying the rows are organisation-level on purpose and `entity_id`/`risk_id` are deliberately unset. |

**Organisation-level rows on a branch node.** A plan or drill with `business_unit_id` NULL (the group
BCP, a corporate drill) appears at organisation scope only, **not** on each branch's node. This is
the widget engine's standing rule ("unattributable rows are out of scope on every node"). It is
also what "that branch's … only" says, and it stops a board from summing one group plan N times
across branch tiles. Record visibility (`ScopedToOrgHierarchy`'s null arm) is a different
question, and the registers still show those rows to every branch.

**What the KRI tile must show.** On a branch node the KRI source counts **none** of the seventeen, and
that is correct: an empty or "0" tile is honest, while an organisation number on a branch page is
not. The seeded tile's name and description are what stop anyone reading the organisation-wide
figure as a branch's.

**Rejected.** (b) There is no BCMS anchor on the KRI row (`automation_config` is json and carries
only `source`/`kri_code`). Setting `entity_id` means choosing one node in a forest for a figure
that belongs to the whole tenant, which is a fabricated branch. (c) One row per branch would mean
17 × N rows, and 16 producers across Phases 1–10 would have to start computing per unit. Until they
did, every branch row would repeat the organisation figure or stay null. Adding `bcms_*` tables to
`ObjectSourceMap` would put one graph object on every plan and occurrence, a change to the whole
graph that also needs a `node_id` column the tables do not have.

**Criterion 5, amended (compliance-analyst applies this to spec §6):** *"BCMS widgets publish through
the existing Dashboards builder and render on a branch org node, showing that branch's drills and
plan status only: rows whose business unit (a drill's is its definition's) is in the node's
subtree. Organisation-level rows appear at organisation scope only. The seventeen resilience KRIs
and the maturity score are organisation-level by definition and are not branch-scoped; on a branch
node the KRI source shows none of them."*

**Proof, in `tests/Feature/Bcms/Phase11WidgetsTest.php`.** (i) Drills at Kano and Lagos plus one
corporate drill: the Kano node shows Kano's drills (and a child unit's, via `inherit_subtree`),
Lagos shows its own, and organisation scope shows all three. (ii) The same three-way test for plan
status. (iii) After `adopt()`, the KRI count is **0** on a branch node and **17** at organisation
scope. This **replaces** the `forceFill(['node_id' …])` test, which proves a row shape no product
path produces. (iv) A viewer without `bcms.exercise.view` gets `forbidden`. `TprmWidgetTest` and
`TprmWidgetHqRenderTest` must stay green unedited after change 1.

**Freeze delta: still 0 tables, 0 columns, 0 indexes.** Two additive `node_column_kind` arms are a
widget-engine vocabulary change, not a graph or schema change.
