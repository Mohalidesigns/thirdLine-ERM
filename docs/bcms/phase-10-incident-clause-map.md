# BCMS Phase 10 — Incident, crisis and IT DR: clause map, reportability and evidence rules

**Agent:** compliance-analyst · **For:** backend-engineer (lead), then ui-designer
**Standards:** ISO 22320:2018 · ISO 22361:2022 · ISO 22301:2019 cl. 8.4.2–8.4.5, 8.5, 9.1, 10.1,
10.2 · CBN Risk-Based Cybersecurity Framework · CBN Open Banking Guidelines · NDPA 2023 s.40 +
GAID 2025 · DORA arts. 17–19 (benchmark)
**Reads:** `plans/bcms/prompts/PHASE-10-incident-crisis-itdr.md`, Blueprint §4.1(8)(9)(10), §9.5,
§12(8), `docs/bcms/phase-9-aar-clause-map.md`, `docs/compliance/cbn-obligations.md`,
`docs/compliance/ndpa-register.md`

**One correction to the prompt's standard list before anything else.** ISO 22336:2024 is *Security
and resilience — Organizational resilience — Guidelines for resilience policy and strategy*
([ISO](https://www.iso.org/standard/50073.html)) — it is a policy-and-strategy guidance standard and
has nothing to say about incident handling. It belongs to Phase 1 if anywhere. The companions that
actually govern this phase are **ISO 22320** (incident response and command) and **ISO 22361**
(crisis management), both already in the taxonomy. There is no `iso22336.*` code and none is needed.

**New clause refs published by this phase: one.** `ndpa.breach_notification` (§5). Everything else
maps to existing cases.

---

## 1. Incident lifecycle, the escalation matrix, and what to stamp

### 1.1 The lifecycle, and the states the column already allows

`bcms_incidents.status` allows `open|contained|recovering|closed|cancelled`; `activation_level`
allows `monitor|standby|partial|full`. That is sufficient — do not widen it. The ISO 22320/22361
response arc maps onto it like this:

| Arc step | Stored as | Non-negotiable rule |
|---|---|---|
| **Detection** | `detected_at` | Distinct from declaration. **Every regulatory clock runs from detection or awareness, never from declaration** — an organisation cannot shorten a reporting window by declaring late. The migration already says this; enforce `detected_at <= declared_at`. |
| **Declaration** | `declared_by`, `declared_at`, `status = open` | The activation criteria of the candidate plan are shown at the moment of decision, and which criterion was met is written to the log as the first `decision` entry. |
| **Activation of the response structure** | `activation_level` + `bcms_plan_activations` row | `plan_id` pins the version, because an approved plan is immutable and superseded rather than edited (`Plan::isImmutable()`). **Do not add a `plan_version` column.** |
| **Response** | `bcms_incident_log` entries (`decision`, `action`, `communication`, `escalation`), `bcms_incident_tasks`, EMNS alerts with `incident_id` | Append-only in practice: a correction writes a new entry with `supersedes_entry_id`, never an update. |
| **Situation reports** | `bcms_incident_log` with `entry_type = situation_report`, chained by `supersedes_entry_id` | That chain **is** the versioning the prompt asks for. Distribution is an EMNS alert carrying `incident_id`. No SitRep table. |
| **Containment / recovery** | `status = contained`, then `recovering` | For an IT disruption, this is where actual outage and data loss against RTO/RPO are captured (§3.4). |
| **Stand-down** | `status = closed` **plus** an explicit `escalation`-or-`decision` log entry recording the stand-down decision and the all-clear communication | A status flipped to closed with no all-clear is how staff stay at an assembly point after the incident ends. ISO 22320 treats stand-down as an act, not an absence of activity. |
| **Post-incident review** | The AAR machinery — see §4 | Not a second table. |
| **Closure** | `closed_at`, every task terminal, every reportable notification recorded, PIR finalised | A reportable incident may not be closed with `is_reportable = true` and `regulator_notified_at` null and no recorded decision that it was not in fact reportable. |

### 1.2 `iso_clause_ref` — exactly what to stamp

| Artefact | Stamp | Notes |
|---|---|---|
| `bcms_incidents` row | `iso22320.incident_response` | Add `iso22361.crisis_management` **instead** where `activation_level = full` and the crisis team was convened — crisis management is a different capability from incident response and an examiner asks for it separately |
| `bcms_plan_activations` | `iso22301.8.4.2` (response structure) | The activation of a plan evidences that the structure exists and was used |
| `bcms_incident_log` `decision` entries | `iso22361.crisis_management` | The decision log is the ISO 22361 artefact and the first thing a post-incident inquiry reads |
| `bcms_incident_log` `situation_report` and `communication` entries, and incident EMNS alerts | `iso22301.8.4.3` (warning and communication) | Already the stamp `AlertService` uses |
| Regulator / NDPC notification record | `cbn.rcf.incident_response` · `ndpa.breach_notification` (new) · `bofia.continuity` where NDIC is notified | One record per regulator. Never one record with a list of regulators |
| `bcms_dr_systems` | `iso22301.8.3` (strategies and solutions) | The arrangement. Its *target* traces to `iso22301.8.2.2` through the BIA |
| `bcms_dr_tests` | `iso22301.8.5.exercise` where it has an `occurrence_id`; otherwise `iso22301.8.4.5` (recovery) | Plus `cbn.open_banking.failover` / `.dr_test` / `.threshold` where the system is in scope (§3.3) |
| Backup / replication attestation | `iso22301.8.3` | An attestation with no signatory is not an attestation (§3.5) |
| Findings from the PIR | `FindingSource::Incident` defaults to `iso22320.incident_response`; a nonconformity names the clause it **failed** (Phase 9 §3.2 table applies unchanged) | |
| Post-incident review row | **`iso22320.incident_response`, and never `iso22301.8.5.report`** | See §4.2. This is the most important stamping rule in this document |

### 1.3 Severity and escalation — a configurable matrix over the bank's own numbers

`severity` is `sev1..sev4`. **The thresholds are not ours to invent.** The matrix ships as a default
built from numbers the tenant already owns — the BIA's MTPD/RTO (Phase 2), `is_critical_service`,
and CBN's 30-minute Open Banking threshold — and the classification *inputs* follow DORA Art. 18(1)'s
six criteria, which are the best-published statement of what makes an incident major: clients or
counterparts affected and transactions affected; duration including service downtime; geographical
spread; data losses (availability, authenticity, integrity, confidentiality); criticality of the
services affected; economic impact, direct and indirect
([Art. 18](https://www.digital-operational-resilience-act.com/Article_18.html)).

| Severity | Default trigger (any one) | Activation level | Consequence the system applies |
|---|---|---|---|
| **sev1** | A critical service (`is_critical_service`) down, or forecast to be down, beyond its approved BIA RTO; or any life-safety impact; or an Open-Banking-in-scope service past 30 minutes; or a confirmed personal-data breach with high risk to data subjects | `full` | Crisis team convened; CMP activation proposed; `bcms.alert.life_safety` templates pre-proposed; both reportability clocks presumed to run until a named officer records otherwise |
| **sev2** | A prioritised activity disrupted but inside its RTO, or running on a documented workaround; single site or single business line | `partial` | Department heads and the programme owner notified; BCP activation proposed |
| **sev3** | Contained to one system with no customer impact and no data loss | `standby` | Logged and run; no crisis team |
| **sev4** | Minor or near miss | `monitor` | Logged; feeds trend reporting only |

Two rules on top: severity may be **raised or lowered only with a `decision` log entry giving the
reason** (a severity that drifts silently is how a sev1 becomes a sev3 by the time the board sees
it); and the matrix is tenant-configurable, with the shipped defaults marked as defaults on screen.

---

## 2. Reportability — clocks, content, and what must be captured by when

### 2.1 What is corroborated, and what is not

| Obligation | What the source says | Confidence |
|---|---|---|
| **CBN Risk-Based Cybersecurity Framework** (DMBs/PSBs, 2024 ed.) | Cyber incidents are to be **reported to the CBN within 24 hours** of the incident occurring, with a further update where the first report was incomplete; participation in NigFinCERT drills/industry exercises is mandatory; non-compliance is sanctionable under BOFIA 2020 | **Corroborated, not read.** The [CBN PDF](https://www.cbn.gov.ng/Out/2024/BSD/CBN%20Risk-Based%20Cybersecurity%20Framework%20for%20DMBs%20and%20PSBs_2024.pdf) returns HTTP 403 to this session's fetch tool; the 24-hour figure is corroborated by an independent [overview of the framework](https://www.mondaq.com/nigeria/security/1518574/overview-of-the-cbn-risk-based-cybersecurity-framework-and-guidelines-for-deposit-money-banks-and-payment-service-banks). **Read the PDF and record the section number before this ships to a customer.** |
| **The CBN report's required content** | — | **Not corroborated.** I could not obtain the framework's prescribed report template or field list. The content list in §2.3 is assembled from what the framework's reporting purpose requires and from DORA's equivalent; it must be a **configurable template**, not a hardcoded CBN form, and the screen must not claim CBN prescribes these exact fields |
| **A NigFinCERT reporting window distinct from the CBN's** | — | **Not corroborated.** Do not build a second NigFinCERT clock on an assumed number. Model NigFinCERT as an additional *recipient* of the incident notification |
| **An NDIC incident-reporting timeline** | — | **Not corroborated.** BOFIA/NDIC relevance here is continuity of operations and resolution planning, not an incident clock (`docs/compliance/cbn-obligations.md` §5) |
| **NDPA 2023 s.40(2)** | The controller must notify the NDPC **within 72 hours** of becoming aware of a reportable breach, and **may provide the information in phases** where it cannot all be given in time | Corroborated ([Mondaq](https://www.mondaq.com/nigeria/data-protection/1371660/data-breaches-compliance-obligations-under-the-nigerian-data-protection-act-2023), [Afriwise](https://www.afriwise.com/blog/data-breaches-compliance-obligations-under-the-nigerian-data-protection-act-2023)) |
| **NDPA 2023 s.40(3)** | Where the breach is likely to result in a high risk to the rights and freedoms of a data subject, communicate to the data subject **immediately**, in plain language, with mitigation steps | Corroborated (same sources) |
| **NDPC GAID 2025** | Restates the 72-hour notification and immediate data-subject communication; in force since **19 September 2025** | Corroborated ([Mondaq](https://www.mondaq.com/nigeria/privacy-protection/1606106/the-nigeria-data-protection-commission-issues-the-general-application-and-implementation-directive-2025-gaid)). **`docs/compliance/ndpa-register.md` was written against the Act and should be re-checked against GAID** — flagged as a gap, not fixed here |
| **CBN Open Banking** | Quarterly failover exercises; DR plans tested every six months; 30 minutes of downtime as the failover/fail-back threshold | As Phase 0 recorded and Phase 9 re-corroborated |

> **Two clocks run at once and they start from different events.** CBN's runs from the incident
> occurring/being detected; the NDPC's from the controller becoming *aware* that a personal-data
> breach occurred. They are not the same moment and one `reporting_due_at` column cannot hold both
> (§6, gap 2).

### 2.2 DORA arts. 17–19 as the benchmark — and still no `dora.*` code

Consistent with Phase 0 and Phase 9: DORA is not a Nigerian obligation, so it gets no clause code.
It is the best-specified incident-reporting regime in the benchmark set and it shapes the field list.

- **Art. 17** — an ICT-related incident management process that detects, manages and notifies, with
  procedures to identify, track, log, categorise and classify incidents by priority and severity.
- **Art. 18(1)** — the six classification criteria in §1.3.
- **Art. 19(1), (4)** — major incidents are reported to the competent authority in **three** reports:
  an **initial notification**, an **intermediate report** ("as soon as the status … has changed
  significantly", with updates as available) and a **final report** ("when the root cause analysis
  has been completed … and when the actual impact figures are available to replace estimates")
  ([Art. 19](https://www.digital-operational-resilience-act.com/Article_19.html)). **Article 19
  itself states no deadlines** — they sit in the RTS under Art. 20.
- The RTS deadlines widely reported as 4 hours from classification / no later than 24 hours from
  detection, 72 hours intermediate, one month final come from **Delegated Regulation (EU) 2025/301
  art. 5** ([secondary source](https://www.springlex.eu/en/packages/dora/rts-ir-regulation/article-5/)).
  I have **not** read that delegated regulation. Do not hardcode those numbers as DORA deadlines in
  product copy.

**What the product takes from DORA:** the three-report structure. Model a regulator notification as a
**series of submissions against one obligation** (`initial` → `intermediate`/`supplementary` →
`final`), which is also exactly what NDPA s.40(2)'s "in phases" and the CBN's "update where the
earlier report was incomplete" both require. One row per submission; the obligation carries the clock.

### 2.3 Fields that must exist by when, for a report to be assemblable

| By | Fields |
|---|---|
| **At declaration (T+0)** | `detected_at`, `declared_at`, `declared_by`, `incident_type`, provisional `severity`, `business_unit_id`/`site_id`, `impacted_processes`, and the two reportability questions answered explicitly: *is this a cyber/operational incident reportable to the CBN?* and *does it involve personal data?* — **"unknown" is a permitted answer and must be a recorded one**, with a re-prompt on the crisis-room screen until it resolves |
| **Within 24 h (CBN)** | Nature and cause as then understood; systems and channels affected; date/time of occurrence and of detection; customers/transactions/amounts affected; containment and mitigation actions taken; current status; whether a personal-data element exists; reporting officer. Submitted-at, channel and reference recorded as evidence |
| **Within 72 h (NDPC)** | Nature of the breach; categories and approximate number of data subjects and of records; likely consequences; measures taken or proposed; DPO/contact point. Phased supplementary submissions supported |
| **Immediately, where high risk (NDPC s.40(3))** | The data-subject communication itself: plain-language description and mitigation steps. Store the text that was sent, not a note that it was sent |
| **At closure** | Root cause, final impact figures replacing estimates, corrective actions with owners, and the final regulator submission where one is owed |

A countdown is only evidence if the deadline it counted to is stored. `reporting_due_at` must be
**written at classification and never recomputed on read** — a countdown derived at render time
answers "when did you look", not "what was the deadline".

---

## 3. IT DR — structure, evidence and the joins that already exist

### 3.1 DRP versus BCP

The BCP is the business-side plan for a prioritised activity; the DRP is the technical runbook for a
system. `bcms_dr_systems.failover_runbook_plan_id` → `bcms_plans` (type `drp`) is the join, and it
is the whole relationship. **A DR system with a null runbook is a register entry with no procedure**
and belongs on the register screen as a gap, in the same way a must-reach call-tree node with no
deputy does (Phase 6). A DRP whose steps have never been walked is the ladder problem again: the
`WALKTHRU` exercise type exists for exactly this.

### 3.2 RTO/RPO evidence traces to the approved BIA, and the join is already built

`bcms_dr_systems.application_id` → `bcms_applications`; `bcms_dependencies` rows with
`dependable_type = application` carry `assessment_id`; `bcms_bia_assessments` carries `process_id`,
`rto_hours`, `rpo_minutes` and `status`. So:

> **Tier-mismatch rule (criterion 4):** for a DR system, take the **minimum `rto_hours` across
> approved BIA assessments** of processes that depend on its application. If
> `rto_target_hours > that minimum`, or the recovery tier's ceiling exceeds it, flag a mismatch —
> and **name the process, its BIA reference and the two numbers**. "Tier mismatch" as a bare badge
> is not actionable. Only `approved` assessments count: a draft BIA is somebody's work in progress
> and must not silently retier production.

### 3.3 Regulatory cadence without a new column (criteria 5 and 4)

`bcms_processes.regulatory_flags` (JSON) already carries the driver. Scope flows
**process → dependency → application → DR system**: a system supporting a process flagged for Open
Banking inherits the quarterly failover cadence, the six-monthly DR-test cadence and the 30-minute
threshold. `next_test_due` is then written from the inherited cadence, not typed by a user, and a
system four months past a quarterly failover is overdue on the Phase 4 compliance calendar view.
**Do not add `is_open_banking` to `bcms_dr_systems`** — it would be a second copy of a designation
that lives on the process, and the two would disagree.

MariaDB 10.4 note: `regulatory_flags` is JSON and `JSON_CONTAINS` is the trap
`CalendarService.php:410` documents. Resolve scope in PHP over a scoped fetch, or with a
`LIKE`-free portable predicate — not with a raw JSON function.

### 3.4 A real invocation is not a DR test

The most valuable RTO/RPO evidence a bank ever gets is from a real outage, and it must not be
written into `bcms_dr_tests`. A row there means "a test we planned and ran"; using it for an
unplanned invocation inflates the test register, slides `next_test_due` forward and lets a bank
report a test it never scheduled. **Record the invocation as an incident with a plan activation, and
put the actuals in the PIR's `quantitative_results`** (§4.3). `bcms_dr_systems.last_test_*` stays
test-only. What an auditor asks for after a real invocation, in this order:

1. Who authorised the failover, when, and against which runbook version (`bcms_plan_activations`).
2. The timeline, including the decision to fail over and the decision to fail back.
3. Actual outage against RTO, and data loss against RPO, with how each was measured.
4. Whether failback happened, when, and whether anything written at DR was lost in the return.
5. The data-integrity verification — verified usable, not merely present.
6. Customer, regulator and staff communications, with what was said and when.
7. The corrective actions and their verification.

A `failover` test with no `failback` test scheduled or recorded is half a test, and the register
should say so — the type catalogue already ships `DRFAILBACK` for it.

### 3.5 Backup attestations, without a table

`last_backup_verified_at` is one timestamp with no actor and no statement, and an attestation with
no signatory is not an attestation (the same argument `cbn-obligations.md` makes about the board
attestation). Acceptable without a migration: the attestation writes `last_backup_verified_at`
through a service that emits a `bcms_audit_logs` entry with `event = backup.attested`, the actor,
the IP and the **statement text in `after`**. That gives actor, time and statement, which is the
minimum. A `bcms_backup_attestations` table would be better and needs an architect ADR; I do not
consider it blocking.

### 3.6 Ingested provider results (criterion 8)

`bcms_dr_tests.evidence` is JSON, so ingestion needs no new table — unlike Phase 9. Require inside
it: `provider`, `external_test_id`, `received_at`, the **raw payload as received**, and a content
hash. Ingestion is idempotent on `(provider, external_test_id)`; a re-delivered webhook updates
nothing and writes no second row. Inbound authentication reuses Phase 7's contract —
`docs/bcms/phase-7-inbound-token-contract.md` — rather than inventing a second scheme, and the
endpoint never accepts `met_objectives` from the provider: whether the objective was met is our
judgement against our target, not the vendor's claim about their own tool.

---

## 4. The post-incident review, and the link to Phase 1 CAPA

### 4.1 The prompt's design is right and the schema currently forbids it

`bcms_aars.occurrence_id` is **`NOT NULL` and `UNIQUE`** with an FK to
`bcms_exercise_occurrences`. "A post-incident review is an AAR row with `incident_id` instead of
`occurrence_id`" therefore **cannot be built today**. This needs an architect ADR before the PIR is
written (§6, gap 1): make `occurrence_id` nullable, add a nullable unique `incident_id` FK, and
enforce "exactly one of the two is set" in the service plus a test. Inventing
`bcms_post_incident_reviews` instead would duplicate the Phase 9 structure and break criterion 9.

### 4.2 What differs from an exercise AAR

| Section | Exercise AAR | Post-incident review |
|---|---|---|
| Clause stamp | `iso22301.8.5.report` | **`iso22320.incident_response`** — a real incident is not an exercise, and stamping it 8.5 would put incidents into the exercise-programme evidence pack and overstate the testing programme to an examiner |
| Objectives vs outcomes | Observer scores against pre-set objectives | **Plan versus actual**: each activated plan section that held, and each that did not. There are no observer scores — nobody was scoring — so the Phase 9 finalisation conditions 5 and 6 do not apply |
| Injects | Scripted, `released_at` | None. Reality supplied them. Condition on inject counts does not apply |
| Participant feedback | Post-exercise survey | Responder debrief, same JSON shape, same no-attribution rule |
| Timeline | `bcms_exercise_timeline` | `bcms_incident_log` — same role, different table. The export must read whichever applies |
| Quantitative results | Exercise metrics | Incident metrics: time to detect, to declare, to activate, to first internal comms, to first customer comms, to regulator notification (against the 24 h / 72 h deadlines), actual outage against RTO and MTPD, data loss against RPO, customers and transactions affected, financial impact, headcount accounted for |
| Extra mandatory section | — | **What the exercises predicted, and what reality exposed** (Blueprint §12(8)). This is the section that justifies the whole exercise programme to a board, and it is the AI capability's output — draft only, `ai_generated`, human-approved |
| Findings | `FindingSource::Aar` | `FindingSource::Incident` → `incident_id`, default clause `iso22320.incident_response` |
| Carried actions | `carried_to_occurrence_id` onto the next occurrence | **Not applicable.** `carried_to_occurrence_id` is the exercise engine's column and Phase 10 must not write it. A PIR action that should be validated at the next exercise is carried by Phase 9's mechanism when that occurrence opens, not by Phase 10 |

Everything else is unchanged: Phase 9's eleven-condition finalisation gate applies with conditions
5, 6 and the inject condition dropped; the severity taxonomy, due-date defaults, verifier-≠-owner
rule and nonconformity closure rule are Phase 1's and Phase 9's, reused verbatim. **The one
due-date change:** a PIR action has no "next occurrence" anchor, so the default is the severity
default alone — except where a regulator was given a remediation date, which then caps it.

### 4.3 Feeding the rest of the system

The ERM bridge already exists one-way: `bcms_incidents.erm_loss_event_id` → `loss_events`, which
itself carries `is_regulatory_reportable`, `regulatory_body` and `reporting_deadline`. **BCMS owns
the operational clock; ERM owns the loss.** Mirror the incident to a loss event where there is a
realised financial loss, and do not write the ERM reportability fields from here — two registers
each asserting a reporting deadline is two answers to one examiner question. `ErmBridge` currently
has `mirrorFinding` and no `mirrorIncident`; that is the method to add, following the same tolerant
pattern (a deployment with no loss register must still be able to run an incident).

---

## 5. NDPA

**New clause ref, and the only one this phase adds:**

```
case Ndpa_breach_notification = 'ndpa.breach_notification';   // NDPA 2023 s.40 — personal data breach notification
```

Seeder row for `Database\Seeders\Bcms\Reference\ClauseRefs` — **required, or
`Phase0FoundationsTest::every_clause_ref_case_is_seeded()` fails**:

```
self::row(IsoClauseRef::Ndpa_breach_notification, 'NDPA 2023', 's.40',
    'Personal data breach notification',
    'Notify the Commission within 72 hours of becoming aware of a reportable personal data breach, '
    .'in phases where the full information cannot be given in time, and communicate immediately to '
    .'data subjects where the breach is likely to result in high risk to their rights and freedoms.',
    'Nigeria Data Protection Act 2023 s.40; NDPC GAID 2025', ['ndpa', 'board'], false)
```

Not a mandatory record under ISO 22301 (it is not an ISO record at all), but it is first-class
evidence: actor, timestamp, immutable state, reference.

**Register additions required in `docs/compliance/ndpa-register.md`** — I cannot write that file
this session, so they are listed here for the next compliance pass:

1. **`bcms_incidents` and `bcms_incident_log` are personal-data records.** A decision log names the
   people who decided; an incident narrative names staff, and often customers and their
   circumstances. Purpose: incident response and regulatory evidence. Lawful basis: legal obligation
   plus legitimate interest. Retention: longer than the roster's, because a regulator may reopen an
   incident years later — propose 7 years from closure, aligned to the loss-event register rather
   than invented separately.
2. **A breach incident's own record contains the breached data's description**, and sometimes
   samples. Rule: **describe categories, never paste records.** The incident log must not become a
   second copy of the breach.
3. **The NDPA 72-hour clock and the CBN 24-hour clock overlap and must not be merged.** A cyber
   incident with a personal-data element owes both, on different clocks, to different regulators,
   with different content. A single "regulator notified" tick satisfies neither.
4. **Data-subject communications are themselves personal-data processing** — store the text and the
   audience rule, resolved through `ContactResolver`, never a free-typed customer list.
5. **GAID 2025 has been in force since 19 September 2025** and the register was written against the
   Act. Re-verify retention, breach handling and the DPCO/audit-return expectations against it.

---

## 6. Acceptance-criteria refinements and gaps, for the backend lead

1. **`bcms_aars` cannot hold a post-incident review (schema, blocking).** `occurrence_id` is
   `NOT NULL UNIQUE`. Criteria 1 and 9 are unbuildable as written. Architect ADR required:
   nullable `occurrence_id`, nullable unique `incident_id`, exactly-one-of enforced in the service
   and in a test. This is the Phase 10 twin of Phase 9's missing evidence table — raise both in one
   ADR conversation.
2. **One clock column, two regulators (schema, blocking criterion 3).** `bcms_incidents` has a
   single `reporting_due_at`, a single `regulator_notified_at` and a single `cbn_reference` — and no
   personal-data-breach flag, no data-subject count, no NDPC reference. Criterion 3 needs an NDPC
   clock running beside the CBN one, and DORA's three-report structure needs many submissions per
   obligation. **Recommended:** ADR for `bcms_incident_notifications` (incident, regulator, basis
   clause ref, awareness/trigger time, `due_at`, `submitted_at`, `submitted_by`, reference, content
   snapshot, submission sequence `initial|intermediate|final|supplementary`). **Fallback with no
   migration:** one `bcms_incident_log` entry per submission with a structured payload, the incident
   keeping `reporting_due_at` as the *earliest open* deadline for the watchdog to index. The
   fallback works; the table is what an examiner's "show me every notification and when it was due"
   really wants.
3. **Six enums are missing over existing columns** (not migrations): `IncidentSeverity`
   (`sev1..sev4`), `ActivationLevel`, `IncidentStatus`, `IncidentLogEntryType`, `DrTestType`,
   `DrStrategy`. Phase 10 is the first writer of all six. Free strings on a register that feeds a
   regulator pack is how `sev1` and `SEV-1` end up in the same column.
4. **Route surface.** As Phase 9: the prompt's `/api/v1/bcms/...` is not how BCMS shipped — routes
   live in `routes/web.php` behind `feature:bcms` (ADR 0007 deviation 2). Follow the shipped
   convention; do not open an `/api/v1` surface for one phase. The **DR ingestion webhook is the one
   exception** — it is machine-to-machine, and it should sit with Phase 7's inbound webhook
   conventions and token contract, not on the Inertia surface.
5. **Criterion 3 needs the clock to survive a reclassification.** An incident classified as a breach
   at hour 10 has 72 hours from *awareness*, not from classification. Test: set `detected_at`
   yesterday, flag the personal-data element today, assert the deadline is yesterday + 72 h and that
   the countdown shows the remaining time, not a fresh 72 hours.
6. **Criterion 2 says "flags the dependent BIA assessments".** There is no flag column, as in Phase 9.
   Use the finding: `affected_process_id` + `iso22301.8.2.2`, surfaced on the BIA screen. Do not
   mutate an approved assessment's status.
7. **Criterion 5's "4 months" needs the cadence derived, not typed.** Implement §3.3's
   process → dependency → application → system scope inheritance and write `next_test_due` from it.
   Watch the MariaDB JSON trap on `regulatory_flags`.
8. **Criterion 7's decision-log export** should be the same export shape as Phase 9's AAR pack, over
   `bcms_incident_log` — timestamp, actor, entry type, content, and what each entry supersedes.
   Build both packs from one presenter or the two will diverge within a phase.
9. **Criterion 6 (roll-call) is Phase 7's dispatcher and must stay there.** Phase 10 dispatches an
   alert with `incident_id` set; it writes no channel code. A roll-call from a live incident is
   `is_simulation = false` and life-safety severity — so it bypasses quiet hours by design, and the
   permission that authorises it is `bcms.alert.life_safety`, which the crisis room must check
   rather than assume.
10. **Criterion 10 (AI post-incident learning) needs its refusals stated**, as in Phase 9: the draft
    may not set `severity`, `is_reportable` or `status`, may not raise findings itself, and nothing
    it produces is submitted to a regulator without a recorded human action (standing rule 4).
    `bcms.incident.notify` already says "Nothing is ever submitted automatically" — hold that line:
    **the product records a notification as submitted; it never submits one.**
11. **`is_exercise` must be honoured everywhere the incident register is read.** A crisis simulation
    declares a real-looking incident. Any count, KRI, board figure or loss-history export that
    forgets `is_exercise = false` reports drills as outages. Add one test per aggregate, not one
    test overall.
12. **Sufficiency verdict.** With gaps 1 and 2 open, an examiner asking *"show me the last reportable
    incident, when you knew, when you told CBN, when you told the NDPC, and what changed as a
    result"* cannot be answered from stored data — there is one clock and one reference. With gap 2
    closed and criterion 8's export in place, it is one click. That is the gate I will hold at the
    Phase 10 sufficiency review.

---

## HANDOFF
**Phase:** P10 — Incident & crisis management + IT disaster recovery
**Agent:** compliance-analyst
**Status:** complete
**Delivered:**
  - `/Users/mac/Documents/devs/laravel/grcsuite/riskerm/docs/bcms/phase-10-incident-clause-map.md` (lifecycle and stamping table; the configurable severity/escalation matrix over DORA Art. 18 criteria; reportability clocks with corroboration status per source; the field-by-deadline list; the DR structure, BIA join, cadence inheritance and real-invocation rule; the PIR-versus-AAR delta; the NDPA additions; 12 refinements)
**Clause refs published:** **one new ref — `ndpa.breach_notification`** (`Ndpa_breach_notification`, NDPA 2023 s.40), with its `ClauseRefs` seeder row given verbatim in §5. Without that row `Phase0FoundationsTest::every_clause_ref_case_is_seeded()` fails. Everything else reuses existing cases: `iso22320.incident_response`, `iso22361.crisis_management`, `iso22301.8.4.2`, `.8.4.3`, `.8.4.5`, `.8.3`, `.8.2.2`, `.8.5.exercise`, `.10.1.*`, `.10.2`, `cbn.rcf.incident_response`, `cbn.open_banking.failover` / `.dr_test` / `.threshold`, `bofia.continuity`. **No `iso22336.*`** — the prompt's third standard is a resilience-policy guideline, not an incident standard. **No `dora.*`** — benchmark only, same decision as Phases 0 and 9.
**Contracts touched:** `iso_clause_ref` taxonomy (Orchestration §5) — **one case added**, which is the compliance-analyst's own contract and requires the enum plus the seeder row in the same commit. `Finding` + `CorrectiveAction` core — consumed as a producer only. `bcms_aars` shape — a change is *requested* of the architect (§6 gap 1), not made. `NotificationChannel` / EMNS — consumed, never extended.
**Assumptions made:**
  - The CBN 24-hour cyber-incident window is corroborated from a secondary source; the primary PDF is 403 to this tool. The CBN report's *content* list is **not** corroborated and is specified as a configurable template rather than a claimed CBN form.
  - No separate NigFinCERT or NDIC incident clock is asserted, because neither could be corroborated. NigFinCERT is modelled as an additional recipient.
  - DORA's 4 h / 72 h / 1 month deadlines are attributed to the RTS (Delegated Regulation (EU) 2025/301 art. 5) via a secondary source and are deliberately **not** hardcoded.
  - The severity matrix thresholds are the tenant's own BIA numbers plus CBN's 30 minutes; the four-band default is labelled a default.
  - Backup attestation via an audit-log event is treated as sufficient without a new table; the architect may prefer the table.
**Known gaps:**
  - `bcms_aars` cannot hold a PIR (`occurrence_id` NOT NULL UNIQUE) — blocks criteria 1 and 9. ADR required.
  - One reporting clock and one regulator reference on `bcms_incidents` — blocks criterion 3 as written. ADR recommended; no-migration fallback given.
  - The CBN framework PDF and the NDPC GAID 2025 both need a direct read: the CBN report content list, and whether GAID changes retention or breach-handling obligations the NDPA register was written against.
  - `docs/compliance/ndpa-register.md` needs the five §5 additions; another agent holds that path this session.
  - `ErmBridge` has no `mirrorIncident()`; the incident → loss-event half of ADR 0001 is unbuilt.
**Next agent:** **ui-designer** — the crisis room (screen 2) is the hard one: it is read by a room of people at once, so the two reportability countdowns must be legible from three metres and must show *what the deadline is*, not only the time remaining. Take the §1.1 lifecycle as the state model, put the §2.3 "answer these two questions now" prompts on the declaration screen (screen 1) with "unknown" as a first-class answer, and design the DR register (screen 4) so a tier mismatch names the process and both numbers per §3.2. Ask the architect for the gap 1 and gap 2 decisions before specifying the PIR screen or the notification panel.
**Verification run:** Sources checked — ISO 22336:2024 scope (iso.org/standard/50073.html) which corrected the prompt's standard list; DORA arts. 18(1), 19(1)/(4) (article text fetched) and the RTS deadline attribution (secondary); NDPA 2023 s.40(2)/(3) and NDPC GAID 2025 (two independent summaries each); CBN RCF 2024 24-hour window (primary PDF 403, one independent overview); `docs/compliance/cbn-obligations.md`, `iso22301-clause-map.md`, `ndpa-register.md` §3; `App\Enums\Bcms\IsoClauseRef` and `Database\Seeders\Bcms\Reference\ClauseRefs` (row signature confirmed); `Incident`, `DrSystem`, `Plan` (`isImmutable()`), `LossEvent`, `AlertService` (`incident_id`, `is_simulation`, dual approval, life-safety queue), `ErmBridge` (four public methods — no incident mirror), `FindingSource::Incident`; the Phase 0 migration for all seven Phase 10 tables plus `bcms_aars`; `bcms-manifest.php` for `bcms_plan_activations` (no version column — none needed), `bcms_processes.regulatory_flags`, `bcms_dependencies.assessment_id`, `bcms_audit_logs`; `RiskPermissionCatalog` (all seven incident/DR permissions already seeded, including the "nothing is ever submitted automatically" wording on `bcms.incident.notify`). Examiner walkthrough — *"show me the last reportable incident and your notifications"*: not answerable today (one clock, one reference); answerable with gap 2 closed. *"show me every decision your crisis team took and who took it"*: answerable from `bcms_incident_log` with `supersedes_entry_id` as the amendment trail. *"show me that this system's DR target matches what the business said it needs"*: answerable through the §3.2 join today, once the mismatch view names the process and the BIA.
