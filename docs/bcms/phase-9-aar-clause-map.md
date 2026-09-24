# BCMS Phase 9 — After-Action Report: clause map, evidence rules and the CAPA loop

**Agent:** compliance-analyst · **For:** backend-engineer (lead), then ui-designer
**Standards:** ISO 22398:2013 · ISO 22301:2019 cl. 8.5, 9.1, 9.2, 10.1, 10.2 · CBN Open Banking
Operational Guidelines · CBN Risk-Based Cybersecurity Framework · DORA arts. 11, 24–26 (benchmark)
**Reads:** `plans/bcms/prompts/PHASE-09-execution-aar.md`, Blueprint §5.6/§5.7/§12(4),
`docs/compliance/iso22301-clause-map.md`, `docs/compliance/cbn-obligations.md`,
`docs/bcms/phase-5-notes.md`, `docs/bcms/phase-6-notes.md`

**No new clause references are published by this phase.** Every artefact Phase 9 creates is
covered by a case already in `App\Enums\Bcms\IsoClauseRef`. That is the intended outcome: the
taxonomy was designed at schema time (Phase 0) precisely so that the phase which finally produces
the 8.5 record does not have to invent anything. DORA gets no code — see §4.

---

## 1. The AAR structure, its mandatory fields, and the clause each carries

### 1.1 What ISO 22398 actually asks for

ISO 22398:2013 *Societal security — Guidelines for exercises* is organised as: exercise
**programme** (planning, conducting, improving), exercise **project** (the individual exercise), and
**continual improvement**, with Annexes A–D ([ISO 22398:2013](https://www.iso.org/standard/50294.html)).
It is a guidance standard, not a certifiable one, and its full text is paywalled — the section list
below is the blueprint's 22398-aligned structure (§5.7) reconciled against the standard's published
clause structure and the evaluation/improvement requirement it carries. **Where a field below is
mandatory, it is mandatory because ISO 22301 or a CBN rule makes it so, not because 22398 does.**
22398 supplies the shape; 22301 8.5 supplies the obligation to retain it.

### 1.2 The nine sections, and the clause each one evidences

| # | AAR section | Stored in | `iso_clause_ref` stamped | Mandatory? |
|---|---|---|---|---|
| 1 | **Exercise identity and design** — aim, objectives, scope, type, ladder level, scenario, date, facilitator, participants | `bcms_exercise_occurrences` (+ `definition`, `exercise_types`, `bcms_scenarios`) | occurrence: `iso22301.8.5.exercise`; the definition's design record: `iso22398.exercise_design` | Yes (8.5) |
| 2 | **Objectives vs outcomes** | `bcms_exercise_scores` + `quantitative_results.objectives[]` | `iso22398.exercise_evaluation` on the evaluation view; the AAR row itself carries `iso22301.8.5.report` | Yes |
| 3 | **Timeline of key events** | `bcms_exercise_timeline` | inherits the AAR's `iso22301.8.5.report` | Yes |
| 4 | **Quantitative results** | `bcms_aars.quantitative_results` (JSON, schema in §2.2) | `iso22301.9.1` for anything registered as a KRI; DR numbers additionally `cbn.open_banking.threshold` where in scope | Yes for any type with a numeric objective |
| 5 | **What worked / what did not** | `bcms_aars.what_worked`, `.what_failed` | `iso22301.8.5.report` | Yes |
| 6 | **Participant feedback** | `bcms_aars.participant_feedback` (JSON, §2.3) | `iso22301.7.3` (awareness) — reported, not stamped on a row of its own | No (product requirement, not a clause) |
| 7 | **Findings** | `bcms_findings` (`source = aar`, `aar_id`) | `iso22301.10.1.nonconformity` for nonconformities; otherwise the clause the finding *failed* (§3.2) | Yes where the exercise failed an objective |
| 8 | **Recommendations → corrective actions** | `bcms_corrective_actions` | `iso22301.10.1.corrective_action` (already set by `CorrectiveActionService::create()`) | Yes for every nonconformity |
| 9 | **Carried-forward chain** — last run's open actions and their disposition | `bcms_corrective_actions.carried_to_occurrence_id` / `.carried_at` + `quantitative_results.carried_actions[]` | `iso22301.10.2` on the chain; `iso22398.exercise_ladder` where the carry crosses a ladder level | Yes — this is the criterion an examiner looks for |

**The AAR row's own `iso_clause_ref` is `iso22301.8.5.report` and nothing else.** It is one of the
eleven mandatory records in `IsoClauseRef::mandatoryRecords()`, which means it must be a first-class
row with an actor, a timestamp and an immutable end state — never a document upload. The occurrence
is `iso22301.8.5.exercise`; the annual programme is `iso22301.8.5.programme`. Three codes, three
retention answers, one query each. Do not collapse them.

### 1.3 Who signs, and in what order

| Act | Permission (already seeded) | Rule |
|---|---|---|
| Score an objective | `bcms.exercise.evaluate` | `evaluator_id` is the signed-in user. No scoring on behalf of another evaluator — a score row with a null `evaluator_id` is rejected even though the column is nullable. |
| Log the timeline, release injects, start/complete | `bcms.exercise.facilitate` | `logged_by` / `released_by` recorded per entry. |
| Override a blocking readiness task | `bcms.readiness.override` | Already built (Phase 5). The override, its reason and its signatory **must appear in the AAR** (`quantitative_results.readiness.overrides[]`). |
| Draft / edit the AAR | `bcms.aar.manage` | Facilitator's job. |
| Approve (`status = final`) and distribute | `bcms.aar.approve` | Sets `approved_by`, `approved_at`, then `distributed_at`. |
| Raise a finding, own a CAPA | `bcms.finding.manage` | Through `FindingService::raise()` — Phase 9 never writes `bcms_findings` directly. |
| Verify a CAPA | `bcms.finding.verify` | Service already refuses the owner and the completer by name. |

**Separation-of-duties rule for approval (product rule, not an ISO requirement — say so on the
screen):** for an occurrence at ladder level `functional` or above, or any occurrence where a
blocking readiness task was overridden, the approver must not be the occurrence's `facilitator_id`.
For `orientation`–`drill` it is a warning, not a block. ISO 22301 does not require this; a bank's own
internal audit function will ask for it, and the exercises where it matters are the expensive ones.

---

## 2. Evidence sufficiency — what an examiner asks for, per exercise type

### 2.1 The common gate: eleven conditions before `status = final`

`AarService::finalise()` refuses, with a per-condition message, unless all of these hold. This is
the whole of the compliance-analyst contribution the backend must implement literally.

| # | Condition | Why |
|---|---|---|
| 1 | `occurrence.actual_start` and `.actual_end` are set | An exercise with no duration was not run |
| 2 | `occurrence.outcome` is set; `inconclusive` requires a stated reason in `summary` | `ExerciseOutcome::satisfiesCadence()` decides whether a regulatory cadence was discharged |
| 3 | `summary`, `what_worked`, `what_failed` all non-empty | "What did not work" empty on a `pass_with_findings` is not a pass |
| 4 | ≥ 1 timeline entry of type `milestone`, and the timeline is not empty | The timeline is the spine of the report |
| 5 | Every objective on the definition has ≥ 1 score row with `evaluator_id` and `objective_text` snapshotted | An unscored objective is an objective nobody evaluated |
| 6 | Every score < 3 has commentary (enforced at write) **and** every score ≤ 2 is either linked to a finding or explicitly dispositioned in `what_failed` | A 2 with no consequence is a 2 nobody acted on |
| 7 | No participant left at `attendance_status = unknown` | Attendance is the 7.3 evidence and the headcount denominator |
| 8 | Every readiness override is listed in `quantitative_results.readiness.overrides[]` | Phase 5: "an override is not a completion and must not look like one" |
| 9 | Every carried action from the previous occurrence has a disposition — `validated` / `still_open` / `superseded` | Otherwise "items to validate" is decoration |
| 10 | `outcome = fail`, or any missed quantitative objective, implies ≥ 1 finding exists | A failed exercise with no findings is not credible to anybody |
| 11 | The per-type required metrics in §2.2 are present, or listed in `not_measured[]` with a reason | A missing number and a number nobody measured are different facts |

Plus, at start rather than at finalisation: `POST /occurrences/{id}/start` calls the **existing**
`ReadinessService::gate()`. Do not write a second gate.

### 2.2 Per-type required metrics — `quantitative_results` JSON schema

`bcms_aars.quantitative_results` is an unschematised JSON column, so the schema is published here and
is the contract the AAR builder screen, the export pack and Phase 11's board pack all read.
Top-level key `"schema": "bcms.aar.quantitative.v1"` so a later version is detectable.

```
{
  "schema": "bcms.aar.quantitative.v1",
  "scope": { exercise_type_code, ladder_level, process_ids[], plans_in_scope[{uuid,version,title}],
             site_codes[], scenario{id,uuid,name}|null, regulatory_drivers[] },
  "objectives": [ { objective_id|null, objective_text, target{value,unit}|null,
                    actual{value,unit}|null, met: true|false|null,
                    mean_score, scores_count, finding_reference|null } ],
  "metrics":     { <per-type keys, table below> },
  "attendance":  { expected, checked_in, absent, excused, unaccounted,
                   by_method{qr,sms,manual,geo}, time_to_assembly_seconds, target_seconds },
  "injects":     { planned, released, released_late },
  "timeline":    { entries, decisions, milestones, first_entry_at, last_entry_at },
  "readiness":   { tasks_total, blocking_open_at_start,
                   overrides[{task, by, at, reason}] },
  "carried_actions": [ { reference, from_occurrence_uuid, disposition, note } ],
  "ladder":      { advisor_warnings[{rule, message}], open_actions_below },
  "sources":     [ { kind: call_tree_test|emns_alert|dr_test|manual, id, captured_at } ],
  "not_measured":[ { metric, reason } ]
}
```

`sources[]` is the generic evidence-source interface the prompt asks for: Phase 6 writes
`call_tree_test`, Phase 7 writes `emns_alert`, Phase 10 writes `dr_test`. Nothing about the AAR
changes as those land.

| Type codes | Required `metrics` keys | The examiner's question |
|---|---|---|
| `FIREDRILL`, `EVAC` | `headcount_expected`, `headcount_actual`, `time_to_assembly_seconds`, `target_seconds`, `unaccounted_resolved` (bool), `mobility_assistance_performed` (bool\|n/a), `roll_call_responses` (EVAC) | "Show me the headcount reconciliation and the list of people you could not account for, and what you did about each" |
| `CALLTREE` | `call_tree_test_id`, `nodes_total`, `nodes_reached`, `completion_rate`, `first_attempt_rate`, `deputy_activation_rate`, `total_cascade_minutes`, `data_quality_failures`, `human_confirmed_completion` (tiers 0–1), `dispatched_completion` (tier 2+), `broken_branches[{node,blocked_count,action_reference}]`, `consent_withheld_count` | "How was the MD informed, by whom, at what time — and who was never reached?" The hybrid split is reported separately (Phase 6 decision); a blended rate flatters the automated half |
| `DRFAILOVER`, `DRFAILBACK`, `DRTEST`, `BACKUP` | `dr_system_ids[]`, `rto_target_minutes`, `rto_actual_minutes`, `rpo_target_minutes`, `rpo_actual_minutes`, `met_objectives` (explicit bool, never defaulted), `downtime_minutes`, `threshold_minutes` (30 where Open-Banking in scope), `threshold_breached` (bool), `failback_performed`, `data_integrity_verified` + how, `abort_trigger_used` | "Did the service come back inside its objective, was the data usable, and did you prove it or assume it?" FFIEC's "validate recovery objectives rather than demonstrate failover" is why `met_objectives` may not default true |
| `CRISISSIM`, `CYBER`, `FUNCTIONAL` | `time_to_convene_minutes` + target, `decisions_logged`, `holding_statement_minutes`, `escalation_decision_at`, `regulatory_window_minutes`, `regulatory_window_met` (bool), `external_parties[]` | "Show me the decision log, and show me when you decided to notify the regulator" (`CYBER` adds the NigFinCERT draft) |
| `TABLETOP`, `PANDEMIC` | `decisions_logged`, `activation_criteria_applied` (bool), `participants_by_role`, `scenario` in `scope` non-null | "Which scenario, who was in the room, which decisions were taken against the documented activation criteria" |
| `WALKTHRU`, `ORIENT`, `SUPPLIER` | `plan_steps_tested`, `plan_steps_failed`, `plans_in_scope` non-empty (with the **version walked**); `SUPPLIER` adds `vendor_id` (TPRM), `vendor_evidence_ref`, `workaround_executable` (bool) | "Which version of the plan did you walk, and which steps could not be executed as written?" |
| `FULLSCALE` | everything in `FUNCTIONAL` plus `activities[{process_id, rto_target, resumed_in, met}]`, `mbco_delivered` (bool), `staff_relocated` | "Did the minimum business continuity objective actually get delivered from the recovery arrangement?" |

**A type with a regulated cadence carries an extra refusal.** `DRFAILOVER` and `DRTEST` carry
`cadence_clause_ref` (`cbn.open_banking.failover` / `cbn.open_banking.dr_test`). For those, an AAR
may not be finalised with `rto_actual_minutes` absent and no `not_measured[]` entry, and the
`threshold_breached` flag must be computed rather than typed. An `inconclusive` outcome on either
must state, on the AAR's face, that the quarter or half-year remains undischarged.

### 2.3 `participant_feedback` JSON, and the NDPA constraint

```
{ "schema": "bcms.aar.feedback.v1", "invited": n, "responded": n,
  "questions": [ { key, prompt, scale: "1-5"|"yesno"|"text",
                   distribution{...}|null, mean|null } ],
  "comments": [ { text, role|null, business_unit|null } ] }
```

Comments carry **role and unit, never `user_id` and never a name**. A post-exercise survey that
attributes "the branch manager did not know who could activate the plan" to a named individual is
employee performance data collected under a continuity purpose, and it will stop people answering
honestly, which destroys the only value the survey has.

---

## 3. Findings → corrective actions

### 3.1 Phase 9 is a producer. What it may and may not do

| Phase 9 owns | Phase 1 owns — call it, do not rebuild it |
|---|---|
| `AarService` (draft, finalise, distribute, amend) | `FindingService::raise()`, `::close()`, `::acceptRisk()` |
| `carried_to_occurrence_id`, `carried_at` — the only writer in the product | `CorrectiveActionService::create/assign/complete/verify/acceptRisk/sweep` |
| The "items to validate" view on readiness and in the AAR template | The register screen (`resources/js/Pages/Bcms/Findings/Index.jsx`) and routes `findings.*` / `actions.*` in `routes/web.php:2353–2371` |
| `quantitative_results`, `participant_feedback`, the export pack | `verify` — already at `POST actions/{action}/verify` behind `bcms.finding.verify` |

The prompt's `GET /corrective-actions` and `POST /corrective-actions/{id}/verify` **already exist**.
Phase 9 adds a `source=aar` filter and a carried-actions column at most. Screen 5 in the prompt is
already shipped.

### 3.2 Classification, severity, and which clause the finding names

Classification (`observation` / `improvement` / `nonconformity`) is the ISO axis and already exists.
**Severity is the management axis and is a free string on the column with no enum and no validator**
— publish it as `FindingSeverity` (`low|medium|high|critical`, values already in the migration
comment). The column exists, so this is an enum, not a migration.

| Severity | Definition (use these words in the UI) | Default CAPA due | Extra rules |
|---|---|---|---|
| `critical` | A prioritised activity could not be recovered inside its MTPD; or a life-safety failure; or a regulated threshold or cadence was breached (30-minute failover, quarterly failover, six-monthly DR test) | **30 days** | Owner must be a function head or above; `acceptance_expires_on` mandatory if accepted; verification requires evidence **or** a successful re-test |
| `high` | An RTO or RPO was missed but the service recovered; a must-reach call-tree node was never reached; a plan step proved unexecutable | **60 days** | `acceptance_expires_on` mandatory if accepted |
| `medium` | An objective scored 2; a deputy chain failed; contact data wrong for a non-must-reach node | **90 days** | — |
| `low` | An observation with no demonstrated impact | **180 days or next programme cycle** | — |

**Clause reference on the finding.** `FindingService` already refuses a nonconformity with no
`iso_clause_ref` and defaults everything else to `FindingSource::Aar->clauseRef()` =
`iso22301.8.5.report`. That default is only right for "the exercise process itself failed". For a
nonconformity the caller passes **the clause that was failed**, not the clause where it was found:

| What the exercise proved wrong | `iso_clause_ref` to pass |
|---|---|
| The plan does not work as written | `iso22301.8.4.4` |
| The warning/communication path failed (call tree, EMNS) | `iso22301.8.4.3` |
| The response structure or command did not function | `iso22301.8.4.2` (or `iso22320.incident_response` for command specifically) |
| Recovery/failback did not return the service | `iso22301.8.4.5` |
| RTO/RPO is unachievable — the BIA's numbers are wrong | `iso22301.8.2.2` (add `iso22317.bia_method` if the method itself is at fault) |
| The chosen strategy cannot deliver the objective | `iso22301.8.3` / `iso22331.strategy` |
| People did not know their role | `iso22301.7.2` (competence) or `iso22301.7.3` (awareness) |
| The exercise programme itself is deficient — no lower rung ever run | `iso22398.exercise_ladder` |
| A CBN cadence or threshold was missed | `cbn.open_banking.failover` / `.dr_test` / `.threshold` / `cbn.rcf.cyber_drills` |
| A critical supplier could not evidence a tested capability | `iso22318.supply_chain` |

### 3.3 Due dates, the carry, and who may close

**Due-date rule:** `due_date = min(severity default, next occurrence of this definition − 5 working
days)`, floored at `raised_at + 5 working days`. An action the next exercise is supposed to validate
must be due *before* that exercise, or "items to validate" arrives already meaningless. Use Phase 4's
`WorkingCalendar` for the working-day arithmetic — do not add days.

**The carry (Phase 9's exclusive column):**
1. On generation or first open of occurrence *N+1* of the same definition, every corrective action
   against a finding from occurrence *N* whose `status->isOpen()` gets `carried_to_occurrence_id =
   N+1`, `carried_at = now()`, and appears as a readiness "item to validate" and in the AAR template.
2. Re-running the carry is idempotent — an action already carried to *N+1* is not re-stamped.
3. **If there is no next occurrence** (retired or year-end definition), fall back to the next
   scheduled occurrence of *any* definition covering the same `process_ids`. If there is none, leave
   it uncarried and surface it on the programme dashboard as *"no scheduled exercise will validate
   this action"*. Silently dropping the carry is how the loop stops being evidence.
4. A carried action is **not** closed by the next exercise running. Closure is still
   `complete` → `verify` by a different person. The re-test route is: `verify` with
   `verification_note` naming occurrence *N+1* and its `outcome` satisfying
   `ExerciseOutcome::satisfiesCadence()`.

**Who may close:** owner completes (`bcms.finding.manage`); a different person verifies
(`bcms.finding.verify` — the service already refuses owner and completer); a nonconformity finding
cannot close until every action against it is `verified` or `accepted_risk` (already enforced);
acceptance of a `critical` nonconformity requires `bcms.finding.accept_risk` **and** an
`acceptance_expires_on`. Nothing here is new code in Phase 1's services — it is Phase 9 passing the
right attributes and refusing at its own API boundary.

### 3.4 Feeding the rest of the system without inventing columns

| Prompt says | There is no such column. Do this instead |
|---|---|
| "flag that plan `review_required`" | `review_required` is **derived** (ADR 0011). Set `needs_review = true` on the specific `bcms_plan_sections` proved wrong through Phase 3's service, and raise the finding with `affected_plan_id` + `iso22301.8.4.4`. `PlanDriftDetector::needsReviewQuery()` then picks the plan up. |
| "flags the related BIA assessment for reassessment" | `bcms_bia_assessments` has no reassessment flag, and moving an approved assessment's `status` would destroy the governance fact (Phase 6's staleness reasoning). **The finding is the flag**: `affected_process_id` + `iso22301.8.2.2`, surfaced on the BIA screen as an open finding against the process. |
| "a call-tree branch failed → raise a tree data-quality CAPA" | Raise against the `call_tree_test_id` that actually ran (Phase 6 deliberately refuses sweep-raised findings with nothing to point at). |
| "moves the resilience KRIs" | Register through the KRI module as Phase 4/5/6 did. P11 aggregates; it registers nothing that exists. |

---

## 4. CBN and DORA — what the AAR must record, and the citations

### 4.1 CBN: what is actually written down

**CBN Operational Guidelines for Open Banking in Nigeria (2023)** — API providers and consumers
must hold a BCP covering OLTP/OLAP architecture, trigger events and failover/fail-back processes,
including **quarterly failover exercises**; **DR plans are to be tested every six months**; and the
threshold for failover and fail-back is **30 minutes of downtime**
([CBN PDF](https://www.cbn.gov.ng/Out/2023/CCD/Operational%20Guidelines%20for%20Open%20Banking%20in%20Nigeria.pdf) —
returned HTTP 403 to this session's fetch tool; the three figures were carried from
`docs/compliance/cbn-obligations.md` §2 (Phase 0, read from the source) and re-corroborated today
against independent summaries ([Afriwise](https://www.afriwise.com/blog/an-overview-of-cbns-operational-guidelines-for-open-banking-in-nigeria),
[Mondaq](https://www.mondaq.com/nigeria/financial-services/1697976/the-compliance-alert-an-overview-of-the-operational-guidelines-for-open-banking-in-nigeria-compliance)).
Re-read the PDF before any customer commitment.)

**CBN Risk-Based Cybersecurity Framework** (DMBs/PSPs 2018, OFIs 2022, 2024 refresh) — maintain and
test BC/DR arrangements for systems supporting critical services and report to the board; conduct
cyber drills and participate in industry exercises; NigFinCERT participation; annual CSAT
(`docs/compliance/cbn-obligations.md` §1, not re-verified this session).

> **CBN does not, in any source we hold, state a general "annual BC/DR exercise" cadence.** The only
> exact cadences in the Nigerian set are Open Banking's quarterly failover and six-monthly DR test.
> The phase must not present an annual default as a CBN requirement. Where the institution's own
> risk assessment sets the cadence, the system says so.

**What the AAR must record for CBN:**
1. `scope.regulatory_drivers[]` — which obligation this run was booked against (from the
   definition's `regulatory_drivers` and the type's `cadence_clause_ref`).
2. Whether the outcome **discharges** the cadence (`ExerciseOutcome::satisfiesCadence()`), and where
   it does not, that the period is short by one. This is the number the compliance calendar view and
   the CSAT pack read; it is not a display nicety.
3. `metrics.downtime_minutes` against `threshold_minutes = 30` and a computed `threshold_breached`
   for in-scope Open Banking services.
4. For `CYBER`: `escalation_decision_at`, `regulatory_window_met`, and whether the NigFinCERT
   notification was drafted and reviewed (a seeded objective on the type already asks this).
5. Board reportability — the AAR's outcome, findings and overdue CAPAs are board-pack inputs
   (Phase 11), which is the "reported to the board" half of the RCF requirement.

### 4.2 DORA — a benchmark, and therefore no clause code

DORA (Regulation (EU) 2022/2554) is not a Nigerian obligation. `docs/compliance/cbn-obligations.md`
deliberately keeps it out of the shipped clause map, and Phase 9 keeps that decision: **no
`dora.*` codes.** Its requirements are satisfied by fields on artefacts that already carry ISO codes,
which is how a customer who later falls in scope gets the evidence without a data migration.

| DORA | Requirement (as published) | What the AAR must carry |
|---|---|---|
| Art. 11(6) | "test the ICT business continuity plans and the ICT response and recovery plans in relation to ICT systems supporting all functions at least yearly", and test the crisis communication plans under Art. 14; non-microenterprises include cyber-attack scenarios and switchover between primary and backup infrastructure ([source](https://www.digital-operational-resilience-act.com/Article_11.html)) | `scope.plans_in_scope[]` with the **version** tested, `scope.scenario`, and a type of `CYBER` / `DRFAILOVER` for the two named scenario classes. The yearly-coverage view is per plan and per critical system, not per exercise |
| Art. 11(8) | keep "readily accessible records of activities before and during disruption events" | The timeline plus the readiness ladder *is* the before-and-during record. This is why the timeline is a table and not a text box |
| Art. 24(5) | "establish procedures and policies to prioritise, classify and remedy all issues revealed throughout the performance of the tests" ([source](https://www.digital-operational-resilience-act.com/Article_24.html)) | The severity taxonomy in §3.2 plus the CAPA due-date defaults **are** that policy. Every missed objective must produce a classified finding |
| Art. 24(5) cont. | internal validation that identified weaknesses are fully addressed | The `verify` step by a second person, and the re-test route in §3.3(4) |
| Art. 24(6) | "at least yearly, that appropriate tests are conducted on all ICT systems and applications supporting critical or important functions" | `scope.process_ids[]` joined to `bcms_processes.is_critical_service` — the coverage matrix (`LadderAdvisor::coverageMatrix()`) already answers this |
| Art. 25 | vulnerability assessments, scans, network security assessments, penetration testing | Out of BCMS scope; a `CYBER` exercise is not a pen test and must not be presented as one |
| Art. 26(1), 26(6) | TLPT "at least every 3 years"; afterwards a summary of findings, the remediation plans and documentation go to the authority ([source](https://www.digital-operational-resilience-act.com/Article_26.html)) | Out of scope for Phase 9. Noted so nobody claims the exercise engine covers TLPT |

The authoritative DORA text is EUR-Lex; the pages cited above are an unofficial consolidation and
were used because they render the article text directly. Cite EUR-Lex in any customer-facing artefact.

---

## 5. Acceptance-criteria refinements and gaps, for the backend lead

Ordered by how much they will cost if found late.

1. **There is no evidence table in the frozen schema, so criterion 8 has nothing to make immutable.**
   The BCMS table list has no `bcms_exercise_evidence` / attachments table; `evidence_file_id` on
   `bcms_exercise_scores` and `bcms_readiness_tasks` is an unconstrained `unsignedBigInteger`
   pointing at no table, and `ReadinessController` validates it as a bare integer. "Photos from
   mobile, files, screenshots" (scope, screen 1) cannot be stored at all today. **Raise an architect
   ADR before writing the workspace**, with two options: (a) a `bcms_exercise_evidence` table —
   occurrence, actor, `captured_at`, kind, storage path, content hash, `iso_clause_ref`, `locked_at`;
   or (b) redefine "evidence artefacts" for this phase as the existing first-class rows (timeline,
   scores, participants, injects, call-tree test nodes) and cut photo capture to Phase 12. I can
   sign off (b) as ISO-sufficient — a photograph is not a mandatory record — but not as
   prompt-complete, and (b) must be a written scope cut, not a silent one.
2. **Route surface.** The prompt's `/api/v1/bcms/...` paths are not how BCMS shipped: routes live in
   `routes/web.php` behind `feature:bcms` (ADR 0007 deviation 2). Follow the shipped convention and
   read the DoD's "API endpoints match Blueprint §10 exactly" as "match the shipped route
   convention". Flag it to the architect rather than opening an `/api/v1` surface nothing else uses.
3. **Two of the prompt's endpoints and one of its screens already exist** (§3.1). Building a second
   register or a second verify path is an automatic review rejection under Orchestration §5.
4. **`FindingSeverity` does not exist.** Phase 9 is the first producer that must set severity to a
   defined scale. An enum over an existing column is not a structural migration; ask for it.
5. **Criterion 1 has no rule for "there is no next occurrence."** Specified in §3.3(3). Add it as a
   test case: a definition with `frequency_per_year = 1` in December must not silently drop the carry.
6. **Criterion 5 is too narrow.** Add: a score row with no `evaluator_id` is rejected; a score of 1–2
   blocks finalisation until it is linked to a finding or dispositioned in `what_failed`.
7. **Criterion 8 needs an amendment path, not only a refusal.** A finalised AAR that turns out to be
   wrong must be correctable or people will keep a second copy in Word. Specify: re-opening a final
   AAR requires `bcms.aar.approve`, writes a `system` timeline entry with the reason, clears
   `approved_by`/`approved_at`, retains `distributed_at` history in the audit log, and requires
   re-approval **and re-distribution**. Freeze the timeline, scores, injects and participant
   attendance for that occurrence while `status = final`.
8. **The T+3 / T+7 AAR-overdue rungs must be voided on `status = final`, not on AAR creation.** A
   draft AAR that exists and says nothing is exactly the case the chasers are for.
9. **No ladder criterion.** Add: starting an occurrence whose `LadderAdvisor::adviseDefinition()`
   returns `ladder_skipped` or `open_actions_below` shows the warning in the workspace, and the
   warning text is copied into `quantitative_results.ladder.advisor_warnings[]` so the record shows
   it was known at the time. The advisor warns and never blocks — do not change that.
10. **No `inconclusive` criterion.** Add: an inconclusive `DRFAILOVER` leaves the quarterly obligation
    undischarged and the compliance calendar view shows the quarter short by one.
11. **The AI draft needs three explicit refusals** beyond criterion 7: it may not set `outcome`, may
    not call `FindingService::raise()` (it proposes; a human raises), and anything it does produce
    carries `ai_generated = true` on both the AAR and any finding a human accepts from it.
12. **There is no examiner export in the API surface, so the sufficiency question fails.** Add
    `GET /occurrences/{occurrence}/aar/export` behind `bcms.report.export`: the AAR, the full
    timeline, scores with evaluators, the attendance reconciliation, the readiness overrides, the
    `sources[]` index, and every finding with its CAPA status and clause ref — built from stored
    values, computing nothing, modelled on `App\Services\Bcms\Emns\EvidenceExport`. Without it,
    Phase 11's ISO and CSAT packs have to reassemble Phase 9's evidence, and "in one click" becomes
    "a human assembled it".
13. **NDPA register needs a Phase 9 amendment and I cannot write it this session** (another agent
    holds `docs/compliance/`). Required additions to §3 of `docs/compliance/ndpa-register.md`:
    (a) photographic evidence of identifiable staff at assembly points — purpose, retention, and no
    faces where a wide shot serves; (b) observer commentary naming individuals, which is employee
    performance data collected under a continuity purpose; (c) `participant_feedback.comments[]`
    free text, held by role and unit only (§2.3). The DoD line "personal data touched → NDPA note"
    is not satisfiable by Phase 9 until this lands.
14. **Sufficiency verdict, stated now so QA can test it:** with items 1 and 12 unresolved, an
    examiner asking *"show me the last fire drill"* gets the AAR on screen and no single artefact
    they can take away. With item 12 built, the answer is one click. That is the gate I will hold at
    the Phase 9 sufficiency review.

---

## HANDOFF
**Phase:** P9 — Exercise execution workspace & After-Action Reports
**Agent:** compliance-analyst
**Status:** complete
**Delivered:**
  - `/Users/mac/Documents/devs/laravel/grcsuite/riskerm/docs/bcms/phase-9-aar-clause-map.md` (AAR structure and clause map; the finalisation gate; the `quantitative_results` and `participant_feedback` JSON schemas; severity taxonomy and CAPA due-date rules; the carry rules; CBN/DORA fields; 14 refinements)
**Clause refs published:** **none — deliberately.** Every Phase 9 artefact maps to an existing case in `App\Enums\Bcms\IsoClauseRef`: `iso22301.8.5.report` (the AAR, a mandatory record), `iso22301.8.5.exercise`, `iso22301.8.5.programme`, `iso22398.exercise_design`, `iso22398.exercise_evaluation`, `iso22398.exercise_ladder`, `iso22301.9.1`, `iso22301.10.1.nonconformity`, `iso22301.10.1.corrective_action`, `iso22301.10.2`, plus the failed-clause set in §3.2 and the CBN cadence codes. No `dora.*` codes: DORA is a benchmark, not a Nigerian obligation, and its fields sit on artefacts that already carry ISO codes.
**Contracts touched:** `iso_clause_ref` taxonomy (Orchestration §5) — **read, not changed**. `Finding` + `CorrectiveAction` core (Track A) — consumed as a producer only; `carried_to_occurrence_id` semantics specified, still the exercise engine's exclusive column. New published contract: the `bcms.aar.quantitative.v1` and `bcms.aar.feedback.v1` JSON schemas, which the AAR builder, the export pack and Phase 11 all read.
**Assumptions made:**
  - ISO 22398's full text is paywalled; the section list is the blueprint's 22398-aligned structure reconciled with the standard's published clause structure. Every *mandatory* field is mandatory because ISO 22301 8.5/10.1 or a CBN rule makes it so.
  - The CBN Open Banking PDF returned HTTP 403 today; its three figures are carried from Phase 0's reading of the source and re-corroborated against two independent summaries.
  - The approver-≠-facilitator rule at `functional` and above is a product rule, labelled as such, not an ISO requirement.
  - `FindingSeverity` as an enum over the existing column is treated as not needing an ADR; the architect may disagree.
**Known gaps:**
  - No evidence/attachment table exists in the frozen schema — architect ADR required before the workspace is built (refinement 1). Blocks prompt criterion 8 as written.
  - No AAR export endpoint in the phase's API surface — without it the phase is not evidence-sufficient (refinement 12).
  - `docs/compliance/ndpa-register.md` §3 needs the Phase 9 amendment; another agent holds that path this session (refinement 13).
  - `bcms_plans` has no `review_required` and `bcms_bia_assessments` no reassessment flag; §3.4 gives the no-migration path, which the architect should confirm.
**Next agent:** **ui-designer** — design the AAR builder (screen 4) against §1.2's nine sections and §2.2's schema, with the §2.1 finalisation gate shown as a live per-condition checklist rather than a failure on submit; the observer scoring card (screen 2) enforcing commentary below 3 and refusing an unattributed score; and the workspace (screen 1) surfacing the ladder warnings from §5(9) and the readiness overrides. Ask the architect for the refinement-1 decision before specifying photo capture on screen 1.
**Verification run:** Sources checked — ISO 22398:2013 clause structure (iso.org/standard/50294.html); DORA arts. 11, 24, 25, 26 (article text fetched); CBN Open Banking Operational Guidelines 2023 (primary PDF 403, corroborated via Afriwise and Mondaq summaries); `docs/compliance/iso22301-clause-map.md` and `cbn-obligations.md`; `App\Enums\Bcms\IsoClauseRef` (52 refs, all cases read); `FindingService`, `CorrectiveActionService`, `LadderAdvisor`, `ReadinessService::gate()`, `EvidenceExport`; the frozen schema for all six exercise-execution tables plus `bcms_findings` / `bcms_corrective_actions`; `database/schema/bcms-manifest.php` (full table list — confirmed no evidence table); `RiskPermissionCatalog` (all six Phase 9 permissions already seeded); `routes/web.php:2347–2371` and `2485–2553` (register, verify and readiness routes already shipped; no `start` route yet). Examiner walkthrough — *"show me your last fire drill"*: today the AAR renders on screen and no takeaway artefact exists; with refinement 12 built it is one click. *"show me every nonconformity from an exercise this year, with the clause it failed and whether it is closed"*: answerable today from `bcms_findings` (`source = aar`) with `iso_clause_ref` and CAPA status. *"show me that the last drill's actions were checked at the next one"*: answerable from `carried_to_occurrence_id` once §3.3 is implemented, and not before.
