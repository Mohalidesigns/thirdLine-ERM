# BCMS Phase 11 — Training & competency · Supply-chain resilience · Reporting, analytics & evidence

**Agent:** compliance-analyst (**lead — accountable for this phase, not consulted**)
**Next:** ui-designer → backend-engineer → frontend-engineer → qa-engineer (gate) → code-reviewer (gate)
**Standards:** ISO 22301 cl. 7.2, 7.3, 9.1, 9.2, 9.3 · ISO 22318 · CBN CSF/CSAT · CBN Open Banking ·
DORA (benchmark)
**Reads:** `plans/bcms/prompts/PHASE-11-training-reporting-compliance.md`, Blueprint §4.1(10)(11)(12),
§9.5, §17, `docs/compliance/iso22301-clause-map.md`, `docs/bcms/phase-9-aar-clause-map.md`,
`docs/bcms/phase-10-incident-clause-map.md`

This is the phase where the product's promise is kept or broken. Everything below is written so that
the answer to *"show me your last DR test report and the corrective actions arising"* is a download,
not a project.

**New clause refs published: none.** Phase 11 assembles; it does not extend the taxonomy. Phase 10's
`ndpa.breach_notification` is the last addition.

---

## 1. Clause map

### 1.1 Competence and awareness — 7.2 and 7.3

| Clause | `iso_clause_ref` | Artefact | Stored in | Sufficiency rule |
|---|---|---|---|---|
| **7.2 Competence** *(mandatory record)* | `iso22301.7.2` | A competency record with an **assessor, a date and a result** | `bcms_training_records` with `competency_assessed = true`, `assessor_id` non-null, `score >= curriculum.pass_mark` | Attendance alone is **not** 7.2. A record with `completed_at` and `competency_assessed = false` evidences 7.3 and nothing more. The matrix must count the two separately or it will report awareness as competence |
| **7.3 Awareness** | `iso22301.7.3` | Attendance, awareness-campaign reach, and exercise participation | `bcms_training_records` (attendance), `bcms_alerts` (campaign reach and acknowledgement), `bcms_exercise_participants` | An awareness campaign with a recipient count and no acknowledgement data evidences dispatch, not awareness. Report reach and engagement separately |
| **7.3 / 7.2 via exercises** | stamp the training record `iso22301.7.2` only where an assessor confirmed it | `bcms_training_records.occurrence_id` — the column already exists for this | A warden who ran a drill has *demonstrated* competence, and criterion 6 auto-links it. **The link creates the record; it does not set `competency_assessed`.** A machine cannot assert somebody is competent; the exercise evaluator can, and that is one click on the AAR |

### 1.2 Performance evaluation — 9.1, 9.2, 9.3

| Clause | `iso_clause_ref` | Artefact | Home today | Verdict |
|---|---|---|---|---|
| **9.1 Monitoring and measurement** | `iso22301.9.1` | Resilience KRIs and their measurements | `key_risk_indicators` + `kri_measurements` (platform module) | **Green once the definitions are seeded** — §2.5. Nothing registers them today (§7 gap 2) |
| **9.2 Internal audit programme** *(mandatory)* | `iso22301.9.2.programme` | An audit programme with scope, criteria, frequency and independence | **Nothing.** There is no `audit_plan` / `audit_engagement` table anywhere in the repo; `issues` is the only audit-adjacent register | **RED, honestly** — §7 gap 1. This is the phase's headline finding |
| **9.2 Internal audit results** *(mandatory)* | `iso22301.9.2.results` | Audit findings with the clause each failed | `bcms_findings` with `source = audit` (+ `erm_issue_id` → `issues`) | **AMBER.** Findings can exist; an audit *report* cannot. The matrix must say "findings recorded; no audit report held in the system" rather than showing green |
| **9.3 Management review results** *(mandatory)* | `iso22301.9.3.results` | Minuted review with an inputs **snapshot**, decisions and an approver | `bcms_management_reviews` — built in Phase 1: `openManagementReview()`, `captureReviewInputs()`, `approveManagementReview()` | **Green.** Phase 11 adds the agenda content pack and extends the snapshot (§2.4) |
| **9.3 inputs** | `iso22301.9.3.inputs` | The nine-ish clause 9.3.2 inputs as they stood on the day | `bcms_management_reviews.inputs` + `inputs_captured_at` | Amber until the snapshot is extended — it currently covers maturity, findings, corrective actions, exercises and plans, and omits incidents and lessons learned, audit results, previous-review actions, interested-party feedback, and DR/call-tree/EMNS performance |

### 1.3 Suppliers — ISO 22318

| Requirement | `iso_clause_ref` | Artefact | Where it lives |
|---|---|---|---|
| Supplier continuity capability is understood, evidenced and monitored | `iso22318.supply_chain` | The vendor's own BCP/DR test evidence, its RTO commitment, our participation, and the next-due date | **TPRM: `tp_bcp_tests`** (engagement-scoped: `test_date`, `test_type`, `our_participation`, `rto_achieved_hours`, `rpo_achieved_hours`, `outcome`, `findings_raised`, `evidence_document_id`, `next_due_at`) |
| Supplier awareness/training obligations | `iso22318.supply_chain` | `tp_awareness_attestations` (`delivered_at`, `participants`, `next_due_at`) | TPRM |
| Supplier criticality, MTPD/RTO and substitutability | `iso22318.supply_chain` + `iso22301.8.2.2` | `tp_business_functions` (`criticality`, `mtpd_hours`, `rto_hours`, `rpo_hours`, `mbco`), `tp_engagements` (`supports_critical_function`, `is_material_outsourcing`, `substitutability`, `time_to_replace_months`, `exit_plan_required`) | TPRM |
| Exit and continuity of critical services | `iso22318.supply_chain` | `App\Models\Tprm\ExitPlan`, `ConcentrationAnalysis` | TPRM |
| **What BCMS adds** | `iso22318.supply_chain` | The **dependency edge**: which of *our* prioritised processes depend on this vendor, at what criticality, with or without an alternative | `bcms_dependencies` with `dependable_type = 'tprm_third_party'` — already in the morph map |

### 1.4 CBN and DORA

| Item | What it needs from this phase | Corroboration |
|---|---|---|
| **CBN CSF — Cyber Resilience (BC/DR), Incident Response and Recovery, Cyber Drills** | The `cbn_csf` pack: the drill AARs, the DR test register, the incident register with its notification timings, and the CAPA chain | Per `docs/compliance/cbn-obligations.md` §1 and Phase 10 §2.1. The 24-hour incident window is corroborated from a secondary source; the primary PDF is 403 to our tooling |
| **CBN CSAT (annual self-assessment)** | A pre-fill that maps our artefacts to CSAT questions | **Not corroborated and not obtainable here.** We do not hold the CSAT workbook, so "the correct response cells" are unknowable — see §3.5. Do not invent cell coordinates |
| **CBN Open Banking** | The `cbn_open_banking` pack: quarterly failover evidence, six-monthly DR test evidence, 30-minute threshold compliance | Corroborated (Phase 9 §4.1) |
| **CBN CSF §2.3 vendor obligations** | Vendor awareness (annual) and vendor BCP-test participation feed the `cbn_csf` pack from TPRM | The section references `§2.3(ii)` and `§2.3(vii)` are **carried from the TPRM build's own migration comments**, which were written against the framework. I have not re-read the primary; treat them as TPRM's citation, not a fresh one |
| **CBN Corporate Governance 2023 — board oversight** | The board pack, plus `bcms_programmes.board_attested_by/_at` | Corroborated (Phase 0) |
| **DORA arts. 11–12** | A `dora` pack: annual BCP/response-recovery testing coverage, crisis-communication testing, BIA coverage including third-party dependencies | Benchmark only. **No `dora.*` clause codes** — the pack is a *crosswalk view* over existing ISO refs (§3.4). `bcms_clause_refs.export_packs` has no `dora` key today and does not need one |

> **The obligation register decides applicability, not us.** `bcms_programme_obligations` (Phase 1,
> `seedObligations()`) records which clause refs bind *this* institution, with
> `applies`, `applicability_note`, `owner_id`, `cadence`. A pack must read it: a bank with no
> open-banking licence marks those three not-applicable with a reason, and the pack then stops
> demanding quarterly failover evidence. **A not-applicable clause renders grey with its rationale —
> never red.** Criterion 3 must be refined to say so (§6).

---

## 2. Content packs to ship, with seeder shapes

All follow the established pattern: `Database\Seeders\Bcms\Reference\*`, rows with
`organization_id = null`, `is_system_default = true`, covered by the Phase 0 system-owned test
(ADR 0006 — a null `organization_id` is invisible to `BelongsToOrganization`'s global scope, so every
model over these rows must set `SYSTEM_OWNED = true`).

### 2.1 Role-based curricula — `Reference\TrainingCurricula` → `bcms_training_curricula`

Row shape mirrors `Reference\ExerciseTypes`: `code`, `name`, `description`, `target_roles` (JSON of
platform role names), `modules` (JSON list of `{code, title, minutes, outcome}`), `frequency_months`,
`is_mandatory`, `requires_assessment`, `pass_mark`, `iso_clause_ref`.

| Code | Name | Target roles | Freq | Assessed | Clause |
|---|---|---|---|---|---|
| `BC-AWARE-ALL` | Business continuity awareness | every employee | 12 | no | `iso22301.7.3` |
| `BC-WARDEN` | Floor warden / fire marshal | floor wardens, facilities | 12 | yes, pass 80 | `iso22301.7.2` |
| `BC-CRISIS` | Crisis management team | crisis-manager, exec sponsors | 12 | yes, pass 80 | `iso22301.7.2` |
| `BC-EMNS` | EMNS operator | emns-operator, emns-approver | **6** | yes, pass 90 | `iso22301.7.2` |
| `BC-CHAMPION` | Department BC champion (BIA, plan, call tree) | department champions | 12 | yes, pass 75 | `iso22301.7.2` |
| `BC-ITDR` | IT DR responder (runbook, failover, failback) | it-dr-manager, on-call | 12 | yes, pass 80 | `iso22301.7.2` |

Two deliberate choices. **EMNS operator re-certifies at six months** — that role dispatches to ten
thousand handsets and the cost of a mistake is the highest in the module. **Only `BC-AWARE-ALL` is
unassessed**, because 7.3 asks for awareness and 7.2 asks for competence, and a curriculum that
claims competence without an assessment produces a record that proves attendance.

Module content is locally grounded, not generic: the warden curriculum covers assembly points in a
multi-tenant Lagos office tower and assisting mobility-impaired colleagues; the ITDR curriculum
covers the 30-minute Open Banking threshold and failback; the crisis curriculum covers CBN and
NigFinCERT notification timing and a holding statement.

### 2.2 Awareness campaigns — **no new table**

An awareness campaign is an **EMNS alert** with an awareness template: `bcms_alerts` already carries
`audience_rule`, `recipient_count`, acknowledgement tracking through `bcms_alert_recipients`, and
delivery evidence through `bcms_notification_deliveries`. That *is* reach and engagement, and it is
already examiner-grade evidence. Ship four `bcms_alert_templates` rows (`Reference\AwarenessTemplates`),
severity `informational`, clause `iso22301.7.3`:

1. **Annual BC awareness week** — what the plan is, where to find yours, what the siren means.
2. **Post-incident lessons bulletin** — fed from a closed incident's PIR (Phase 10).
3. **Contact-verification drive** — the campaign that moves `BCMS-CT-CONFIDENCE`; it is the only
   awareness campaign with a KRI attached, and that is why it exists.
4. **New-joiner BC induction** — triggered by the roster, not by a calendar.

### 2.3 Internal-audit checklist — `Reference\InternalAuditChecklist`

Content only, because 9.2 has no table (§7 gap 1). Ship as a clause-indexed checklist keyed to
`IsoClauseRef` — one to four questions per clause group, each naming the artefact that answers it
(e.g. for `iso22301.8.5.report`: *"Select three completed occurrences from the last twelve months.
Is there a finalised AAR for each, and was every corrective action it raised assigned an owner and a
due date?"*). This is the same list the AI gap analyser scores against, so **one source, two
consumers** — do not write the questions twice.

### 2.4 Management-review agenda — `Reference\ManagementReviewAgenda`

The clause 9.3.2 input list as an ordered checklist the review screen renders and
`captureReviewInputs()` fills. **Extend the existing snapshot** (Phase 1 covers maturity, findings,
corrective actions, exercises, plans) with the items the later phases made available:

`previous_review_actions` · `audit_results` · `incidents` (count, severity mix, notification
timeliness, lessons — Phase 10) · `exercise_evaluation_outputs` (AAR outcomes and quantitative
misses — Phase 9) · `call_tree_and_emns_performance` (Phases 6–7) · `dr_achievement`
(RTO/RPO achievement rate — Phase 10) · `supplier_continuity` (expired vendor attestations — §4) ·
`interested_party_feedback` (free text; there is no feedback register and inventing one is out of
scope) · `bia_and_risk_changes` · `context_changes` · `improvement_opportunities`.

It stays a **snapshot with a timestamp**. Phase 1's reasoning holds: a screen that re-queries shows a
reader in December a meeting that considered December's numbers, which is not what happened.

### 2.5 Resilience KRI definitions — `Reference\ResilienceKris` → `key_risk_indicators`

**Nothing in the product registers a BCMS KRI today.** `TreeHealthService::mirrorKris()` looks up
`KeyRiskIndicator::where('kri_code', …)` and `continue`s when it is null — deliberately, "a KRI the
risk team has not defined is not one this module invents on their behalf" — and Phase 2C, which was
to define them, has not been built. So the four call-tree codes are computed and never filed.

**The split that resolves this without a second metrics engine and without breaking criterion 4:**

- **Definitions are content** (this phase, my remit): code, name, description, `unit_of_measure`,
  `direction`, `target_value`, `measurement_frequency`, thresholds, `data_source`, `is_automated`.
  Seeded per tenant on module enable, idempotent on `kri_code`, and **never overwriting a tenant's
  edited thresholds** — a bank that moved a target owns that decision.
- **Measurements are the producing phase's** (already true for call trees), written through the
  existing `mirrorKris()` pattern: one-way, tolerant, `updateOrCreate` on `(kri_id,
  measurement_date)`.

The eleven from Blueprint §17 plus the four already computed and the operational three:

| `kri_code` | Name | Target | Direction | Producer |
|---|---|---|---|---|
| `BCMS-EX-COMPLETION` | Exercise programme completion rate | ≥ 95% | lower_is_worse | P4/P9 |
| `BCMS-EX-ONDATE` | Exercises executed on originally scheduled date | ≥ 80% | lower_is_worse | P4 |
| `BCMS-RD-T1` | Readiness task completion by T-1 | ≥ 90% | lower_is_worse | P5 |
| `BCMS-AAR-7D` | AAR completed within 7 days | ≥ 95% | lower_is_worse | P9 |
| `BCMS-CAPA-ONTIME` | Corrective actions closed by due date | ≥ 85% | lower_is_worse | P1 |
| `BCMS-PLAN-CURRENT` | Plans reviewed within cycle | 100% | lower_is_worse | P3 |
| `BCMS-CT-COMPLETION` | Cascade completion rate | ≥ 98% | lower_is_worse | **P6 — already computed** |
| `BCMS-CT-CONFIDENCE` | Call tree data confidence | ≥ 95% | lower_is_worse | **P6 — already computed** |
| `BCMS-CT-STALE` | Call trees overdue for review | 0 | higher_is_worse | **P6 — already computed** |
| `BCMS-CT-DEPUTY-GAP` | Must-reach nodes with no deputy | 0 | higher_is_worse | **P6 — already computed** |
| `BCMS-EMNS-ACK15` | EMNS acknowledgement within 15 minutes | ≥ 90% | lower_is_worse | P7 |
| `BCMS-T1-TESTED` | Tier-1 processes with tested recovery in 12 months | 100% | lower_is_worse | P9 (`LadderAdvisor::coverageMatrix()`) |
| `BCMS-DR-RTO` | RTO achievement rate in DR tests | ≥ 90% | lower_is_worse | P10 |
| `BCMS-SMS-DELIVERY` | Per-provider SMS delivery rate | ≥ 95% | lower_is_worse | P7 (`ProviderHealth`) |
| `BCMS-DISPATCH-LAG` | EMNS dispatch latency (p95, seconds) | ≤ 60 | higher_is_worse | P7 |
| `BCMS-SCHED-LAG` | Scheduler lag (minutes) | ≤ 15 | higher_is_worse | P5 watchdog |
| `BCMS-VENDOR-ATTEST` | Critical vendors with current continuity evidence | ≥ 95% | lower_is_worse | **P11 (§4)** |

Seventeen definitions, **one new producer** — the vendor one, which is this phase's own. Every other
measurement is written by the phase that owns the data. A `grep` for `bcms_kri` / `bcms_metric`
tables must return nothing (criterion 4).

---

## 3. Evidence assembly

### 3.1 The rule that makes a pack evidence

**Every pack is built from stored rows and recomputes nothing.** `EvidenceExport`'s header states the
reason and it applies here verbatim: a pack that recalculates drifts as the estate changes, and a
pack that disagrees with last month's copy of itself is worse than none. Maturity is already stored
per assessment (`bcms_maturity_assessments` + `_scores`); the exercise programme's `total_planned` is
stored as approved; delivery rows are terminal. Those three facts are what make a March pack reprint
in December unchanged.

**Failures are exported as prominently as successes.** Same rule as the EMNS pack. A pack that shows
only what worked is the bank hiding; a pack that names its own gaps is the bank demonstrating control.

**Generation is recorded.** No `bcms_report_runs` table exists and none is strictly needed: write a
`bcms_audit_logs` entry with `event = pack.exported`, the actor, and the parameters (framework,
period, org node) in `after`. That answers "is this the pack you gave the CBN in March".

### 3.2 The ISO 22301 pack — order and sufficiency, per mandatory record

Assembled in clause order, one section per `bcms_clause_refs` row where `export_packs` contains
`iso22301`, with an index page mapping clause → artefact → page.

| # | Clause / ref | Pulls | Sufficiency rule (what makes the cell green) |
|---|---|---|---|
| 1 | 4.1–4.3 `iso22301.4.*` | `bcms_programmes` (scope statement, out-of-scope statement, interested parties), `bcms_programme_scope` rows | Programme `approved`, and **every exclusion has a rationale row** |
| 2 | 5.2 `iso22301.5.2` | The BC policy plan (`bcms_programmes.policy_plan_id`) | Policy `approved`, approver ≠ author, inside its review cycle |
| 3 | 5.3 `iso22301.5.3` | `bcms_raci_assignments` | Every critical process has an accountable owner |
| 4 | 6.2 `iso22301.6.2` | `bcms_objectives` | Every objective has a measure and a target (clause 6.2 requires measurability) |
| 5 | **7.2** *(mandatory)* | `bcms_training_records` where `competency_assessed = true` | Every role in a mandatory curriculum has a **current, assessed** record. Attendance-only counts amber |
| 6 | 7.3 | attendance records + awareness `bcms_alerts` with acknowledgement rates | A campaign in the period with reach reported |
| 7 | 7.4 / 8.4.3 | `bcms_alert_templates`, `bcms_call_tree_tests`, `bcms_notification_deliveries` | A completed cascade or alert in the period with per-recipient delivery evidence |
| 8 | **8.2.2** *(mandatory)* | `bcms_bia_assessments` where `status = approved` | Every in-scope process has an approved BIA inside its cycle |
| 9 | 8.3 | `bcms_strategies` + the gap analysis | Every critical process has a selected, justified strategy |
| 10 | **8.4.1, 8.4.4** *(mandatory)* | `bcms_plans` approved, with `bcms_plan_sections` | Approved, current, and **acknowledged** (`bcms_plan_attestations`) |
| 11 | 8.4.5 | `bcms_dr_tests` where `test_type = failback` | A failback tested in the cycle — not only a failover |
| 12 | **8.5.programme** *(mandatory)* | `bcms_exercise_programmes` approved, with `total_planned` | The programme **as approved**, plus delivery against it |
| 13 | **8.5.report** *(mandatory)* | `bcms_aars` where `status = final`, with their occurrences | Every completed occurrence in the period has a finalised AAR. A completed occurrence with no AAR is the single most common real finding and must show red |
| 14 | 8.6 | plan review dates, `LadderAdvisor::coverageMatrix()` | No Tier-1 process untested above a walkthrough (Phase 9 rule) |
| 15 | **9.1** | `key_risk_indicators` + `kri_measurements` | Each KRI has a measurement inside its own frequency |
| 16 | **9.2.programme, 9.2.results** *(both mandatory)* | — / `bcms_findings` where `source = audit` | **Red / amber respectively (§7 gap 1).** The pack must print the honest sentence, not omit the section |
| 17 | **9.3.results** *(mandatory)* | `bcms_management_reviews` approved, with the `inputs` snapshot | A review held in the period, approved, inputs captured before approval (already enforced) |
| 18 | **10.1.nonconformity, 10.1.corrective** *(both mandatory)* | `bcms_findings` classification `nonconformity`; `bcms_corrective_actions` | Every nonconformity has ≥1 action; every closed one was **verified by somebody other than its owner** |
| 19 | 10.2 | `bcms_corrective_actions.carried_to_occurrence_id` chain | At least one action carried onto a later occurrence — the continual-improvement evidence a regulator actually looks for |

### 3.3 The CBN packs

**`cbn_csf`**, in the order an examiner works: (1) the BC/DR position — DR system register with targets
vs last actuals, overdue systems named; (2) DR and failover test records for the period with
actual-vs-target and breaches; (3) drill and cyber-exercise AARs; (4) the incident register with
detection, declaration and **notification timings against the 24-hour window**, plus each notification
record; (5) the CAPA chain from every one of the above, with ageing; (6) board reporting evidence
(`board_attested_by/_at` and the board pack for the period); (7) vendor continuity evidence from
TPRM (§4).

**`cbn_open_banking`**: per in-scope service — the four quarterly failover exercises with outcomes,
the two six-monthly DR tests, every `downtime_minutes` against the 30-minute threshold with breaches
flagged, and for any quarter with no qualifying exercise an explicit *"quarter undischarged"* line.
`ExerciseOutcome::satisfiesCadence()` is the arbiter: an `inconclusive` quarter is short by one.

### 3.4 The DORA pack is a crosswalk, not a new taxonomy

`{DORA article → the ISO refs that answer it → the artefacts}`, held in the pack definition:
Art. 11(6) → `iso22301.8.5.exercise` + `.8.4.3` (crisis-communication testing) + `iso22301.8.5.report`;
Art. 11(8) → `iso22320.incident_response` (the incident log and readiness ladder);
Art. 12 → `bcms_dr_systems` backup/replication attestation; BIA coverage including third parties →
`iso22301.8.2.2` + `iso22318.supply_chain`. No `dora` key is added to
`bcms_clause_refs.export_packs`; the crosswalk is a Phase 11 content file.

### 3.5 CSAT pre-fill — what we can honestly build

We do not hold the CBN CSAT workbook, so the cell addresses the prompt's criterion 8 depends on
cannot be known, and **inventing them would put our guess into a bank's regulatory submission**.
Also: the blueprint's "we already parse CSAT with `openpyxl`" is not true of this repository —
`openpyxl` is Python and does not appear here. What does exist is RCSA's PhpSpreadsheet machinery
(`RcsaTemplateWriter`, `RcsaWorkbookWriter`, `RcsaImportProcessor`), and that is what to reuse.

Build it in this shape: (1) a shipped **mapping content pack** of `clause ref → CSAT section and
question label` — labels, not coordinates; (2) the customer uploads their own CSAT workbook for the
year; (3) the writer locates each question by label and writes our answer plus the artefact reference
into the adjacent response cell, leaving anything it cannot locate untouched and **listing every
unmatched question on a cover sheet**. Criterion 8 is then honestly satisfiable. Amend it to say so
(§6).

---

## 4. Supply-chain resilience — read-through, plus one thing BCMS adds

**No new supplier table. No vendor attestation table.** TPRM already holds the attestation evidence
(`tp_bcp_tests`, `tp_awareness_attestations`), the criticality and RTO commitments
(`tp_business_functions`, `tp_engagements`), the exit plans and the concentration analysis. A second
copy in `bcms_*` would be an automatic review rejection under Orchestration §5's architectural rule.

**What Phase 11 builds:**

1. **The BCMS-relevance filter.** A vendor is BCMS-critical when it appears as a
   `bcms_dependencies` row (`dependable_type = 'tprm_third_party'`) against a process that is Tier 1
   or `is_critical_service`. That query, not TPRM's own tiering, is what makes the BCMS vendor list
   different from the TPRM vendor list — and both are legitimate.
2. **The continuity-currency view**: per critical vendor, the latest `tp_bcp_tests` row, its
   `next_due_at`, whether `our_participation` was true, `rto_achieved_hours` against the
   engagement's commitment, and an expiry state. A vendor whose evidence is past `next_due_at` is on
   the chase list and moves `BCMS-VENDOR-ATTEST` (criterion 7).
3. **Vendor exercise participation**: vendors invited onto an occurrence. `bcms_exercise_participants`
   has `user_id` and `contact_id` and **no vendor column** — so a vendor participant is a
   `bcms_contacts` row for the named individual at the vendor, which is how EMNS can reach them
   anyway, with the vendor link carried on the contact. Do not add a column to the participants
   table; do confirm with the architect (§7 gap 4).
4. **The concentration read-through**: which vendors are dependencies of multiple Tier-1 processes.
   Presented from `bcms_dependencies`, **linked to** TPRM's `ConcentrationAnalysis` rather than
   recomputing it. Note honestly on the screen: TPRM's concentration limits have **no
   shareholders'-funds figure behind them** — a deliberate, recorded go-live gap — so the BCMS view
   reports counts and named processes, never a limit-utilisation percentage.
5. **The seam to say out loud**: a BCMS dependency names a **third party**; TPRM's continuity
   evidence hangs off an **engagement**. One vendor with five engagements has five BCP-test records
   and the dependency does not say which service we depend on. Resolve by presenting all engagements
   for the vendor that have `supports_critical_function = true`, and flag the vendor as
   *evidence-ambiguous* where more than one qualifies. Do not guess.

---

## 5. Screens and API, reconciled with what exists

| Prompt screen | Status | Phase 11 work |
|---|---|---|
| 1. BCMS Home | **Exists** — `HomeController` + `BcmsHomePresenter` (programme, counts, `plans_current_rate`, upcoming, sections) | Add maturity score and trend, exercises completed-vs-planned donut, top RTO gaps, overdue CAPA tile |
| 2. Compliance & Evidence | New | The clause matrix over `bcms_clause_refs` × `bcms_programme_obligations`, each cell drilling to artefacts, pack export |
| 3. Board pack preview | New | Sectioned preview + PDF/PPTX |
| 4. Training compliance | New | By role and department, overdue, **competency vs attendance as two columns** |
| 5. Vendor resilience | New | §4's three views |
| 6. My Resilience | Partly exists (`me/readiness-tasks`, my plan, my profile) | One employee page that gathers them |

**Routes: follow the shipped convention, not the prompt's `/api/v1/...`.** BCMS ships Inertia routes
in `routes/web.php` behind `feature:bcms` (ADR 0007 deviation 2), and the existing report surfaces
already sit there (`bia-report`, `bia-report/export`, `maturity/assess`, `strategy.gap.export`,
`plans.acknowledgements.export`, `alerts/{alert}/evidence`). Phase 11 adds, under the same group:

```
training-curricula                      GET, POST            bcms.training.view / .manage
training-records                        POST                 bcms.training.manage
training-records/{record}/assess        POST                 bcms.training.manage
training/compliance                     GET                  bcms.training.view
vendors/continuity                      GET                  bcms.report.view
vendors/{thirdParty}/attestation        POST  -> writes tp_bcp_tests via TPRM's service
vendors/concentration                   GET                  bcms.report.view
metrics/resilience-kris                 GET                  bcms.report.view
reports/compliance-matrix               GET                  bcms.report.view
reports/board-pack?year=&format=        GET                  bcms.report.export
reports/regulatory-evidence?framework=  GET                  bcms.report.export
reports/maturity-heatmap                GET                  bcms.report.view
reports/csat-prefill                    POST (upload+return) bcms.report.export
reports/gap-analysis/ai                 POST                 bcms.report.view
```

Two permission notes. `vendors/{thirdParty}/attestation` writes a TPRM row, so it must check
**TPRM's** authority as well as `bcms.report.view` — a BCMS grant is not a licence to write the
vendor register. And every `reports/*` export is `bcms.report.export`, which per the existing
catalogue is *"Export the ISO 22301, CBN and board evidence packs"* — that wording was written for
this phase.

---

## 6. Acceptance criteria, refined

Numbering follows the prompt; changes are marked.

1. Unchanged — one-click board pack and CBN bundle for a chosen period.
2. **Refined.** The examiner test stands, and the target is the *last DR test report and its
   corrective actions*. Add: the returned pack must be **reproducible** — running it twice over the
   same period yields byte-identical section content — because that is what makes it evidence.
3. **Refined.** Every clause resolves to an artefact, is honestly **red**, or is **grey with the
   `bcms_programme_obligations.applicability_note`** where the institution recorded it as not
   applicable. A not-applicable clause shown red is a false gap; a green cell with no artefact behind
   it is worse.
4. **Refined.** All seventeen KRI *definitions* exist in the platform KRI module and no `bcms_*`
   metrics table exists. **Definitions are seeded by this phase (§2.5) because nothing else
   registers them — Phase 2C was to, and has not been built.** Measurements remain the producing
   phase's, and this phase writes measurements for exactly one KRI: `BCMS-VENDOR-ATTEST`. A test must
   assert Phase 6's four call-tree KRIs are *measured* by `TreeHealthService` and not re-measured here.
5. **Refined (ADR 0021 Amendment 1) —** BCMS widgets publish through the existing Dashboards builder
   and render on a branch org node, showing that branch's drills and plan status only: rows whose
   business unit (a drill's is its definition's) is in the node's subtree. Organisation-level rows
   appear at organisation scope only. The seventeen resilience KRIs and the maturity score are
   organisation-level by definition and are not branch-scoped; on a branch node the KRI source
   shows none of them. See `docs/adr/0021-phase-11-asks-for-no-schema-and-the-kri-register-is-adopted-not-invented.md`,
   Amendment 1, for why (no per-branch reading exists for a KRI or maturity score to lean on).
6. **Refined.** The occurrence links to the participant's training record automatically; setting
   `competency_assessed` remains a human act by an assessor who is not the participant. Test both:
   the link appears without intervention, and the record does **not** claim competence until assessed.
7. Unchanged — an expired vendor attestation appears on the chase list and moves the KRI. Source is
   `tp_bcp_tests.next_due_at`.
8. **Refined per §3.5** — CSAT pre-fill fills a customer-supplied workbook by question label, leaves
   unmatched questions untouched, and lists them on a cover sheet. No invented cell coordinates.
9. Unchanged — the AI gap analyser cites the specific missing artefact with org node and elapsed
   time. Add the standing guardrails: draft only, `ai_generated`, and it may not mark a clause green.
10. Unchanged — PDF and PPTX with Atheris branding.
11. Unchanged — heatmap and trend read `MaturityService`; `Phase1GovernanceTest` already greps for a
    second scorer, so extend that assertion rather than writing a new one.
12. **New.** Two of the eleven ISO mandatory records (9.2 programme, 9.2 results) have no home. The
    matrix must show them red/amber with the honest sentence, and a test must assert the pack still
    generates and still *names* them. A pack that silently omits a clause is the failure mode this
    criterion exists to prevent.
13. **New.** Pack generation writes a `pack.exported` audit entry with actor, framework, period and
    org node.

---

## 7. Schema gaps for the architect

1. **ISO 22301 9.2 has no artefact anywhere in the product (blocking two mandatory records).** There
   is no audit programme or audit report table in `bcms_*` or in the platform — `issues` is the only
   audit-adjacent register, and `FindingSource::Audit` deliberately has no foreign key. Options:
   (a) a small ADR'd pair — `bcms_audit_programmes` (period, scope, criteria, auditor, independence
   statement, approved_by) and `bcms_audit_results` (programme, clause ref, conclusion, report
   reference) — which closes the clause map; (b) accept that the bank's internal audit function
   evidences 9.2 outside the system, and render the two cells red with the sentence *"no internal
   audit programme is held in this system; ISO 22301 9.2 is evidenced from the internal audit
   function's own records"*. **(a) is my recommendation** — it is two thin tables and it is the
   difference between a complete evidence pack and a pack with a hole in the chapter an auditor
   reads first. (b) is acceptable and must then be said out loud on the matrix, not omitted.
2. **Nothing registers a BCMS KRI.** Not a schema gap — a seeding gap, resolved by §2.5. It needs the
   architect's agreement that seeding *definitions* from Phase 11 does not violate Orchestration §5's
   "the phase that produces the metric registers it", because on the current build nobody registered
   anything and criterion 4 is otherwise unsatisfiable.
3. **The dangling-integer family, third instance.** `bcms_training_records.certificate_id` is an
   unconstrained `unsignedBigInteger` pointing at no table, exactly like
   `bcms_exercise_scores.evidence_file_id` (Phase 9 gap 1) and `bcms_readiness_tasks.evidence_file_id`.
   Competency assessment "with evidence" cannot store the evidence. **Resolve all three in one ADR**
   rather than three — one evidence/attachment table with actor, captured_at, hash and clause ref
   serves Phase 9's exercise evidence, Phase 10's backup attestation and this phase's certificates.
4. **Vendor participation on an exercise.** `bcms_exercise_participants` has `user_id` and
   `contact_id` and no vendor reference. §4(3) proposes representing a vendor participant as a
   `bcms_contacts` row carrying the vendor link, which needs no migration — confirm rather than
   assume, because the alternative (a `third_party_id` column) is a structural change.
5. **No `bcms_report_runs` table.** Deliberately acceptable (§3.1): the audit-log entry plus the
   determinism rule covers it. Flagged so the decision is recorded rather than rediscovered.

---

## HANDOFF
**Phase:** P11 — Training & competency · Supply-chain resilience · Reporting, analytics & evidence
**Agent:** compliance-analyst (**lead**)
**Status:** complete — spec delivered; two decisions are the architect's before build starts (gaps 1 and 3)
**Delivered:**
  - `/Users/mac/Documents/devs/laravel/grcsuite/riskerm/docs/bcms/phase-11-spec.md` (clause map for 7.2/7.3 and 9.1/9.2/9.3 plus ISO 22318, CBN and DORA; five content packs with seeder shapes; the ISO and CBN pack assembly order with a sufficiency rule per mandatory record; the TPRM read-through; screens and routes reconciled against what ships; thirteen acceptance criteria; five gaps)
**Clause refs published:** **none.** Phase 11 assembles evidence and does not extend the taxonomy; the last addition was Phase 10's `ndpa.breach_notification`. No `dora.*` key is added to `bcms_clause_refs.export_packs` — the DORA pack is a crosswalk over existing ISO refs (§3.4).
**Contracts touched:** `iso_clause_ref` taxonomy — read only. **KRI registration** (Orchestration §5): this phase seeds the seventeen *definitions* because nothing else ever did, and writes measurements for exactly one (`BCMS-VENDOR-ATTEST`); every other measurement stays with its producing phase. That split needs the architect's nod. Maturity scoring engine — consumed, no second scorer. TPRM tables — read, plus writes to `tp_bcp_tests` **through TPRM's own service and authority check**, never direct.
**Assumptions made:**
  - Seeding KRI definitions from Phase 11 is consistent with "the producing phase registers", because the producing phases register measurements and no definition exists to measure against.
  - Awareness campaigns are EMNS alerts with an awareness template, not a new table — reach and engagement already exist as delivery and acknowledgement evidence.
  - A vendor exercise participant is a `bcms_contacts` row carrying the vendor link (gap 4).
  - CBN CSF §2.3(ii)/(vii) section references are carried from the TPRM build's own citation, not re-read from the primary framework.
  - The CSAT design assumes the customer supplies their own workbook; we ship question-label mappings, never cell coordinates.
**Known gaps:**
  - ISO 22301 9.2 (programme **and** results) has no artefact in the product — two of eleven mandatory records. Architect decision required; the honest-red wording is drafted if the answer is "out of scope".
  - `certificate_id` / `evidence_file_id` dangling integers — one ADR should cover Phases 9, 10 and 11.
  - `target_roles` auto-enrolment depends on Phase 2C's AD group resolution, which is not built; fall back to the platform's own role assignments and say so on the screen.
  - The management-review snapshot needs extending to the later phases' inputs (§2.4) before 9.3 inputs can go green.
  - CSAT primary artefact (the workbook) and the CBN framework PDF both still need a direct read; both were 403 or unavailable to this session.
**Next agent:** **ui-designer** — start with screen 2, the Compliance & Evidence matrix, because it is the screen the phase is judged on and its hardest requirement is honesty: three states, not two (green with artefacts, red with the missing artefact named, grey with the not-applicable rationale from `bcms_programme_obligations`), and every cell a link to the rows in §3.2 rather than a number. Then screen 4, where **competency and attendance must be two visibly different columns** — a single "trained %" column would let a bank report awareness as competence, which is the one thing clause 7.2 exists to prevent. Screen 5 needs the evidence-ambiguous state from §4(5). Do not design a vendor attestation form that writes a BCMS table; it posts to TPRM.
**Verification run:** Sources checked — `plans/bcms/prompts/PHASE-11-*`, Blueprint §4.1, §9.5, §17, §12(5); `App\Enums\Bcms\IsoClauseRef::mandatoryRecords()` (eleven records, each traced to a home or to a gap); `Database\Seeders\Bcms\Reference\ClauseRefs` (row signature and the four existing pack keys — no `dora`); `bcms_clause_refs` and `bcms_programme_obligations` columns; `MaturityService` (the one scorer, `METHOD_VERSION`, stored assessments) and `MaturityClauseGroup` (ten groups, weighted); `ProgrammeService::openManagementReview/captureReviewInputs/approveManagementReview` (9.3 built; snapshot keys enumerated); `TreeHealthService::kris()` and `mirrorKris()` — **confirmed Phase 6 computes four KRIs and registers none**, and that Phase 2C never landed; `KeyRiskIndicator` fillable (definition shape); `EvidenceExport` (the determinism and prominence rules reused); `HomeController` + `BcmsHomePresenter` (screen 1 already partly built); `routes/web.php` BCMS group (existing report and export routes, permission names); `RiskPermissionCatalog` (`bcms.training.*`, `bcms.report.view/export` already seeded); `MorphTypes` + `DependencyType` (`tprm_third_party` is the vendor dependency — read-through confirmed); TPRM migrations — **`tp_bcp_tests`, `tp_awareness_attestations`, `tp_business_functions`, `tp_engagements` already hold everything the prompt asked BCMS to build for vendors**; `ExitPlan` and `ConcentrationAnalysis` models; grep for ERM training/competency tables — **none exist**, so no duplication risk; grep for `openpyxl` — **absent, the blueprint's claim does not hold for this repo**; RCSA's PhpSpreadsheet writers as the real reuse target; grep for audit programme tables — **none**, which is gap 1. Examiner walkthrough — *"show me your last DR test report and the corrective actions arising"*: three clicks (Compliance & Evidence → 8.4.5 cell → export) once §3.2 is built, and the CAPA chain comes with it because the finding carries `dr_test_id`. *"Show me every mandatory ISO record"*: nine of eleven answerable today, two blocked on gap 1. *"Show me your critical suppliers' continuity evidence"*: answerable entirely from TPRM rows through the BCMS dependency filter, with no new table.
