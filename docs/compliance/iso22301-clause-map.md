# ISO 22301:2019 — clause map for the BCMS module

**Owner:** compliance-analyst · **Published:** BCMS Phase 0 · **Status:** the taxonomy is frozen at G0

This is the answer to the only question that matters at an audit: *show me where
that is.* Every row names a clause, the artefact in this system that satisfies
it, where that artefact lives, and how it comes out.

## How to read the "artefact" column

An artefact is a **first-class row with an actor, a timestamp and an immutable
state**, not an uploaded file. Clause 7.5.3 requires documented information to be
controlled; a PDF in an attachments table has no actor and no state, so it
evidences that somebody uploaded something. Where a row below says "attachment",
that is a defect to raise, not a design.

The `code` column is the value of `App\Enums\Bcms\IsoClauseRef` written into the
`iso_clause_ref` column of the artefact's table. **No agent adds a code.** A
phase that needs one raises it here first.

---

## Clause 4 — Context of the organisation

| Clause | Code | Requirement (paraphrase) | Artefact | Table | Export |
|---|---|---|---|---|---|
| 4.1 | `iso22301.4.1` | Internal and external issues affecting the BCMS | Programme scope statement | `bcms_programmes.scope_statement` | ISO pack |
| 4.2 | `iso22301.4.2` | Interested parties and legal/regulatory requirements | Obligation register (`docs/compliance/cbn-obligations.md` seeded to `bcms_clause_refs`) | `bcms_clause_refs` | ISO pack, CBN CSAT |
| 4.3 | `iso22301.4.3` | Scope, and justification for exclusions | Programme scope + out-of-scope statement | `bcms_programmes` | ISO pack |

## Clause 5 — Leadership

| Clause | Code | Requirement | Artefact | Table | Export |
|---|---|---|---|---|---|
| 5.2 | `iso22301.5.2` | A documented, communicated policy | BC policy plan record, version-controlled and approved | `bcms_plans` (`plan_type` carrying the policy) | ISO pack, board pack |
| 5.3 | `iso22301.5.3` | Roles, responsibilities and authorities | Programme owner, exercise owner/facilitator, call-tree activation authority | `bcms_programmes.owner_id`, `bcms_exercise_definitions.owner_id`, `bcms_call_trees.activation_authority_user_id` | ISO pack |
| — | `cbn.governance.board_oversight` | Board oversight (CBN Governance 2023) | Board attestation, with actor and timestamp | `bcms_programmes.board_attested_by`, `.board_attested_at` | board pack |

## Clause 6 — Planning

| Clause | Code | Requirement | Artefact | Table | Export |
|---|---|---|---|---|---|
| 6.2 | `iso22301.6.2` | **Measurable** objectives with a plan to achieve them | Objective with a target value, a unit and a KRI link | `bcms_objectives` (+ `key_risk_indicator_id` → `key_risk_indicators`) | ISO pack, board pack |

> **The measurement is a KRI, not a second metrics engine.** Blueprint §4.2 is
> explicit and Orchestration §5 assigns registration to the phase that produces
> the metric. An objective with no `key_risk_indicator_id` and no
> `target_value` is an aspiration, and the maturity score treats it as one.

## Clause 7 — Support

| Clause | Code | Requirement | Artefact | Table | Export |
|---|---|---|---|---|---|
| **7.2** | `iso22301.7.2` | Competence, **with retained documented evidence** | Training record with an assessor, a score and a competency flag | `bcms_training_records` | ISO pack, CBN CSAT |
| 7.3 | `iso22301.7.3` | Awareness | Attendance record; drill attendance counts | `bcms_training_records`, `bcms_exercise_participants` | ISO pack |
| 7.4 | `iso22301.7.4` | Communication, including during a disruption | Alert templates per channel and locale; the delivery audit trail | `bcms_alert_templates`, `bcms_notification_deliveries` | ISO pack |
| 7.5 | `iso22301.7.5` | Documented information under control | Plan versioning with `supersedes_plan_id` and approval | `bcms_plans` | ISO pack |

> **7.2 and 7.3 are different records and must not be merged.** A curriculum
> with `requires_assessment = false` produces attendance evidence, which
> satisfies 7.3 and does not satisfy 7.2. A system that lets a customer claim
> competence from an attendance sheet produces a clean audit and an untrained
> crisis team.

## Clause 8 — Operation

| Clause | Code | Requirement | Artefact | Table | Export |
|---|---|---|---|---|---|
| 8.1 | `iso22301.8.1` | Operational planning and control | The programme, its status and its year | `bcms_programmes` | ISO pack |
| **8.2.2** | `iso22301.8.2.2` | BIA: impact over time, prioritised timeframes, resources | Approved BIA with MTPD/RTO/RPO/MBCO, impacts per horizon, dependencies | `bcms_bia_assessments`, `bcms_bia_impacts`, `bcms_dependencies` | ISO pack, CBN CSAT, board |
| 8.2.3 | `iso22301.8.2.3` | Risk assessment of disruption | Link from process to the ERM risk register | `bcms_processes` → `risks` (one-way bridge, ADR 0001) | ISO pack |
| 8.3 | `iso22301.8.3` | Strategies and solutions, with resources | Approved strategy with cost, achievable RTO and the gap | `bcms_strategies` | ISO pack, board |
| **8.4.1** | `iso22301.8.4.1` | Plans and procedures (**mandatory record**) | Approved plan with an effective date and a review date | `bcms_plans` | ISO pack, CBN CSAT |
| 8.4.2 | `iso22301.8.4.2` | Response structure and activation thresholds | Incident activation levels; the crisis call tree | `bcms_incidents.activation_level`, `bcms_call_trees` (`tree_type = crisis_team`) | ISO pack |
| 8.4.3 | `iso22301.8.4.3` | Warning and communication, **including when the primary means fails** | EMNS with an offline-capable channel set; the call tree and its tests | `bcms_alerts`, `bcms_call_tree_tests`, `bcms_settings.life_safety_channel_set` | ISO pack, CBN CSAT |
| 8.4.4 | `iso22301.8.4.4` | Plans with roles, activation and resumption procedures | Plan sections bound to live BIA and call-tree data | `bcms_plan_sections` | ISO pack |
| 8.4.5 | `iso22301.8.4.5` | Recovery — returning from temporary measures | DR failback test records | `bcms_dr_tests` (`test_type = failback`) | ISO pack, CBN |
| **8.5** | three codes | The **exercise programme**, the **individual exercise**, the **post-exercise report** | The annual programme, each occurrence, each AAR | `bcms_exercise_programmes`, `bcms_exercise_occurrences`, `bcms_aars` | ISO pack, CBN CSAT, board |
| 8.6 | `iso22301.8.6` | Evaluation of documentation and capability | Plan review dates; exercise outcomes | `bcms_plans.next_review_date`, `bcms_exercise_occurrences.outcome` | ISO pack, board |

> **8.5 is split into three codes and that split is load-bearing.** An auditor
> asks for the programme (what you planned for the year), the exercise (what you
> did on the day) and the report (what you found) as three separate things, with
> three different retention answers. A single `8.5` code cannot answer "show me
> your exercise programme as approved" without also returning forty
> occurrences.

## Clause 9 — Performance evaluation

| Clause | Code | Requirement | Artefact | Table | Export |
|---|---|---|---|---|---|
| 9.1 | `iso22301.9.1` | Monitoring and measurement | Resilience KRIs in the existing KRI module | `key_risk_indicators`, `kri_measurements` | ISO pack, board |
| **9.2** | two codes | Internal audit **programme** and **results** (both mandatory records) | The thirdLine audit programme and its findings, synced as BCMS findings | `bcms_findings` (+ `erm_issue_id` → `issues`) | ISO pack, board |
| **9.3** | two codes | Management review **inputs** and **results** (results mandatory) | `bcms_management_reviews` — attendees, a **snapshot** of the clause 9.3 inputs, decisions, approval | `bcms_management_reviews` | ISO pack, board |

## Clause 10 — Improvement

| Clause | Code | Requirement | Artefact | Table | Export |
|---|---|---|---|---|---|
| **10.1** | two codes | Nonconformity and corrective action (both mandatory records) | Finding classified `nonconformity`; corrective action **verified by somebody other than its owner** | `bcms_findings`, `bcms_corrective_actions` | ISO pack, CBN CSAT, board |
| 10.2 | `iso22301.10.2` | Continual improvement | The carried-forward chain: an action from one exercise appearing on the next | `bcms_corrective_actions.carried_to_occurrence_id` | ISO pack, board |

> **`completed` and `verified` are two states and clause 10.1 needs both.** The
> clause asks whether the action *worked*, which the person who did it cannot
> answer about themselves. A register that stops at `completed` records
> intentions.

---

## Evidence sufficiency — the examiner walkthrough

The test the compliance-analyst applies after QA on every phase: *if an examiner
asked "show me", could the system show them, in one click, without a human
assembling anything?*

| Examiner question | Answered by | Status at G0 |
|---|---|---|
| Show me your last DR test report and the corrective actions arising | `bcms_dr_tests` joined to `bcms_findings` → `bcms_corrective_actions` | Schema in place; the screen lands in Phase 10 |
| Show me your exercise programme for the year, as approved, and what you actually delivered | `bcms_exercise_programmes` (`total_planned`, `total_completed`, `approved_at`) vs `bcms_exercise_occurrences` | Schema in place; Phase 4 |
| Show me that everyone on the crisis team is competent | `bcms_training_records` where `competency_assessed = true` | Schema in place; Phase 11 |
| Show me who you told, and when, in your last incident | `bcms_alert_recipients` + `bcms_notification_deliveries`, both snapshotted at dispatch | Schema in place; Phase 7 |
| Show me the branch of your call tree that failed and what you did about it | `bcms_call_tree_test_nodes.downstream_blocked_count` → `bcms_findings` | Schema in place; Phase 6 |

## Gaps at G0, and what Phase 1 did about them

1. ~~**Management review (9.3) has no home table.**~~ **Closed in Phase 1.**
   `bcms_management_reviews` was added under ADR 0008 — the first structural
   migration after the freeze, and the one this section predicted. The inputs
   are a **snapshot**: a review held in March considered March's CAPA status,
   and re-deriving it for a reader in December would rewrite what the meeting
   looked at. A review cannot be approved before they are captured.
   **Actions arising are corrective actions**, against a finding whose source is
   `management_review` — not a second action register.
2. **Policy is stored as a plan record.** Confirmed in Phase 1 and it does not
   strain: `bcms_plans` already models a versioned, approved, supersedable
   document with an owner and an approver, and `PolicyService` adds the one rule
   that matters — an approved version is immutable and is superseded, never
   edited. `PlanType::Policy` and `bcms_plan_attestations` complete it.
3. **The ERM risk link on `bcms_processes` is not yet a column,** and Phase 1
   decided it should stay that way. BCMS deliberately creates **no ERM risks**:
   a missed drill is not a new risk, and the disruption it exercises is already
   in the register. Continuity exposure reaches the register through the KRIs the
   clause 6.2 objectives are measured by, which is the join Blueprint §4.2 asks
   for. If Phase 2 finds it needs a direct edge, that is an ADR.

### New gaps opened by Phase 1

4. **`bcms_programme_obligations.cadence_per_year` has nothing to compare
   against yet.** The obligation register knows CBN Open Banking requires four
   failover exercises a year; proving the calendar delivers four needs Phase 4's
   occurrences.
5. **No evidence-pack export.** Every artefact carries its `iso_clause_ref` and
   `bcms_clause_refs.export_packs` says which pack it belongs in. Assembling the
   pack is Phase 11.

## HANDOFF

**Phase:** P0 — Foundations & schema freeze (updated after P1)
**Agent:** compliance-analyst
**Status:** complete; revisited at the end of Phase 1, which closed gap 1 and confirmed gaps 2 and 3
**Delivered:** `docs/compliance/iso22301-clause-map.md`, `docs/compliance/cbn-obligations.md`, `docs/compliance/ndpa-register.md`, `App\Enums\Bcms\IsoClauseRef`, `Database\Seeders\Bcms\Reference\ClauseRefs`
**Clause refs published:** 52 — 29 ISO 22301 (5 to sub-clause level on 8.4, 8.5, 9.2, 9.3 and 10.1), 8 companion-standard, 15 Nigerian
**Contracts touched:** `iso_clause_ref` taxonomy (Orchestration §5) — frozen at G0
**Assumptions made:** the BC policy is filed as a `bcms_plans` record rather than a table of its own; management review is deferred to Phase 11 with an ADR flagged
**Known gaps:** the three above
**Next agent:** backend-engineer for Phase 1; compliance-analyst returns after QA on every phase for the sufficiency review
**Verification run:** every enum case has a seeded row and every seeded row has an enum case, asserted by `Phase0FoundationsTest`; the mandatory-record set matches ISO 22301's own list of retained documented information
