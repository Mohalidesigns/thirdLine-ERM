# Nigerian regulatory obligations for the BCMS module

**Owner:** compliance-analyst · **Published:** BCMS Phase 0

Nigeria is the primary market and these obligations are the buying trigger. Each
row names the source, what it requires, the feature that satisfies it, and the
**cadence**, because for most of these the requirement is not "have a plan" but
"test it this often".

> **Citations are to the named document, not to a recollection of it.** Where a
> figure below is exact — quarterly, six-monthly, thirty minutes — it is stated
> in the source. Where the source is qualitative, this document says so rather
> than inventing a number to make a default look authoritative. **Confirm the
> current version of each source against the CBN's own publication before a
> customer commitment is made**; the framework in particular has been reissued
> for different institution classes (DMBs/PSPs 2018, OFIs 2022, refreshed 2024)
> and the applicable one depends on the client's licence.

---

## 1. CBN Risk-Based Cybersecurity Framework and Guidelines

Applies per licence class. The parts that reach this module:

| Part | Requirement | BCMS feature | Cadence | Code |
|---|---|---|---|---|
| Cyber Resilience — BC and DR | Maintain and test BC/DR arrangements for systems supporting critical services, reported to the board | The exercise engine; the DR register; the board pack | Testing cadence set by the institution's own risk assessment; board reporting at the board's cycle | `cbn.rcf.bc_dr` |
| Incident Response and Recovery | Incident response capability, CBN reporting, NigFinCERT participation | Incident module with `is_reportable`, `reporting_due_at`, `regulator_notified_at`, `cbn_reference` | Per incident, against the reporting window in the framework | `cbn.rcf.incident_response` |
| Cyber Drills and Industry Exercises | Conduct cyber drills; participate in industry exercises; retain evidence and the actions arising | `CYBER` exercise type, its readiness template, its AAR and CAPA chain | At least annually in practice; confirm against the applicable version | `cbn.rcf.cyber_drills` |
| Self-assessment (CSAT) | Complete and submit the annual self-assessment | The evidence pack export, `export_packs` containing `cbn_csat` | Annual | `cbn.rcf.csat` |

**What the module does with this.** The CSAT export pack is assembled from
artefacts that already carry `iso_clause_ref`, so a drill's AAR reaches the
self-assessment without anybody re-keying it. This is the "we already parse CSAT
with openpyxl" integration Blueprint §2.3 names; Phase 11 owns the export.

## 2. CBN Operational Guidelines for Open Banking

The most *specific* cadences in the Nigerian set, and therefore the ones the
calendar engine ships as defaults.

| Requirement | BCMS feature | Cadence | Code |
|---|---|---|---|
| BCP covering OLTP/OLAP architecture, trigger events, failover and fail-back | The plan builder, with the DR runbook linked from `bcms_dr_systems.failover_runbook_plan_id` | — | `cbn.open_banking.failover` |
| Failover exercises | `DRFAILOVER` exercise type, shipped at `default_frequency_per_year = 4` | **Quarterly** | `cbn.open_banking.failover` |
| DR plan testing | `DRTEST` exercise type, shipped at `default_frequency_per_year = 2` | **Every six months** | `cbn.open_banking.dr_test` |
| Failover / fail-back threshold | RTO validation against a 30-minute ceiling for in-scope services | **30 minutes** of downtime | `cbn.open_banking.threshold` |

> **These two cadences are the only frequencies in the shipped catalogue that
> carry a `cadence_clause_ref`.** Everything else in
> `Database\Seeders\Bcms\Reference\ExerciseTypes` is a starting point a client's
> risk assessment should move. Marking a cadence as regulatory when it is not
> would put our opinion in a client's compliance file.

**Design consequence.** A client who lowers `frequency_per_year` below a
regulated cadence is told which rule they are now outside — a warning with a
citation, not a block. Blocking would be us enforcing a rule against an
institution whose licence may not carry it.

## 3. CBN Supervisory Framework for Payment Service Banks

Carries a dedicated BCMS section. Satisfied by the module as a whole; the PSB
programme template is a Phase 11 content deliverable. Code: `cbn.psb.bcms`.

## 4. CBN Corporate Governance Guidelines 2023

Board oversight of risk, including operational risk. Satisfied by
`bcms_programmes.board_attested_by` / `.board_attested_at` — an attestation with
an actor and a timestamp, not a tick — plus the board pack export. Code:
`cbn.governance.board_oversight`.

> The attestation permission sits with `board-member` and `chief-risk-officer`
> and with nobody else. A board attestation an operations user can record is not
> a board attestation.

## 5. BOFIA 2020 / NDIC

Continuity of banking operations and the arrangements supporting resolution
planning. Satisfied by the critical service register:
`bcms_processes.is_critical_service`, which is deliberately a **different flag
from `criticality_tier`**. A tier is our assessment of how badly a disruption
would hurt; a critical service is a regulatory designation. A tier-1 process is
not automatically a critical service and a critical service is not automatically
tier 1. Code: `bofia.continuity`.

## 6. NDPA 2023 / NDPC

Covered in full by `docs/compliance/ndpa-register.md`. Three codes reach the
clause map: `ndpa.lawful_basis`, `ndpa.retention`, `ndpa.residency`.

---

## International benchmarks — what mature examiners expect

Not obligations for a Nigerian licence, and they shape what "good" looks like.
They are **not** in the shipped clause map as obligations; they are here so that
a design decision can point at a benchmark rather than at an opinion.

| Source | What it adds |
|---|---|
| FFIEC BCM Booklet | Business line management owns testing its own operations; results go to the board; scenarios must validate recovery objectives rather than demonstrate failover; third-party threats are in scope |
| EU DORA arts. 11–12 | Test BC and ICT response/recovery plans at least yearly and after substantive change; test the crisis communication plan; BIA must consider third-party dependencies and interdependencies |
| APRA CPS 230 | Identify critical operations, set tolerance levels, demonstrate remaining within them under severe but plausible scenarios |
| BCBS Principles for Operational Resilience | The pan-African umbrella framing for the whole module |

**The one that changed a design decision:** FFIEC's "validate recovery objectives
rather than merely demonstrating failover" is why `bcms_dr_tests` stores
`rto_actual_minutes` and `rpo_actual_minutes` against target, and why
`met_objectives` is nullable rather than defaulting true. A DR test that
completed is not a DR test that met its objectives, and a register that cannot
tell them apart is a register that reports the wrong thing to a board.

## Regional expansion (post-launch)

Ghana (BoG Cyber & Information Security Directive), Kenya (CBK Guidance Note on
Cybersecurity), South Africa (SARB Joint Standard on IT Governance & Risk),
Egypt (CBE). **Not seeded.** A clause code that ships before somebody has read
the directive is a code that will be wrong in a customer's evidence pack.
