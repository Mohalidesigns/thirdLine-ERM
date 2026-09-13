# ADR 0008 — The Phase 1 schema additions

**Status:** Accepted · **Date:** 2026-09-08 · **Phase:** BCMS Phase 1 · **Author:** architect
**Requested by:** compliance-analyst (lead, Phase 1) · **Broadcast to:** Tracks B, C, D, E

## Context

Standing rule 2 forbids structural migrations after Phase 0. This is the first
ADR against that rule, and one of the seven items below was **predicted in the
Phase 0 handoff** (`docs/bcms/phase-0-notes.md` §5.1): ISO 22301 clause 9.3,
management review, has no home table, and Phase 1's acceptance criterion 7
requires clause 9.3 to be evidenced.

The other six emerged from reading the Phase 1 prompt against the frozen schema.
Each is a thing the phase is *required* to deliver and cannot deliver with the
columns that exist. None of them changes a column another track reads.

**This ADR is the process working, not the process failing.** The freeze was
never meant to mean "the schema is perfect"; it means a change is visible, argued
and broadcast rather than merged quietly on a Friday. The manifest
(`database/schema/bcms-manifest.php`) is regenerated in the same commit, so
`bcms:verify-schema` continues to fail on anything not listed here.

## Decision

One migration, `2026_09_10_120001_create_bcms_governance_tables.php`. Seven new
tables and six new columns; **nothing is renamed and nothing another track reads
is altered**, so no branch has to rebase on it.

### New tables

| Table | Why it cannot be done with what exists |
|---|---|
| `bcms_management_reviews` | Clause 9.3 is a **mandatory documented record** and its results are a distinct artefact from a programme approval. `bcms_programmes.approved_at` records that a programme was signed off; 9.3 asks what the review took as *input* (audit results, exercise outcomes, CAPA status, KRI performance), who attended, and what was *decided*. Predicted in the Phase 0 handoff. |
| `bcms_programme_scope` | The prompt requires in- and out-of-scope org nodes and processes. `scope_statement` is prose; "which branches are in scope" is a question the BIA, the calendar and the evidence pack all have to answer as a set, not by reading a paragraph. Morph over `business_unit` and `bcms_process`, using aliases already in `MorphTypes`. |
| `bcms_programme_obligations` | The tenant's obligation register: **which** of the shipped `bcms_clause_refs` apply to this institution, who owns each, and what cadence it drives. `bcms_clause_refs` is the system-owned library (no `organization_id` at all, ADR 0006); it cannot carry a tenant's applicability decision. |
| `bcms_raci_assignments` | RACI per process and per programme, with a gap report. Nothing in the schema expresses "accountable", and `owner_id` is one person in one role. Morph over programme and process. |
| `bcms_maturity_assessments` + `bcms_maturity_scores` | The maturity engine's output must be **stored**. Same reasoning as `tp_engagements.effective_tier` and `bcms_call_tree_tests.scorecard`: a score recomputed on read reprints differently in June, and a board pack that changes retrospectively with nothing recording that it changed is worse than no board pack. Phase 11 builds views over these and adds no second scorer. |
| `bcms_plan_attestations` | Board attestation of the BC policy is **annual** and is "a dated record with the signer, not a checkbox". `bcms_programmes.board_attested_at` is one attestation of one programme; a policy attested every year for five years needs five rows. |

### New columns

| Column | Why |
|---|---|
| `bcms_processes.critical_service_justification` | `is_critical_service` is the BOFIA/NDIC resolution-planning register. A regulatory designation recorded as a bare boolean is one nobody can defend at an examination; the prompt is explicit that the flag needs a justification. |
| `bcms_programmes.interested_parties` (json) | Clause 4.2. Prose in `scope_statement` cannot be exported as the list an auditor asks for. |
| `bcms_programmes.policy_plan_id` (FK → `bcms_plans`) | The programme's approved policy **version**. Phase 0 left `policy_document_id` as an unconstrained bigint pointing at a document-control module this product does not have. |
| `bcms_objectives.baseline_value`, `.baseline_captured_at` | Clause 6.2 objectives are measurable; a target with no baseline cannot show movement, and "improve plan currency to 90%" means nothing without what it was. |
| `bcms_findings.source` (string) | The prompt requires five source types — `aar`, `incident`, `call_tree_test`, `audit`, `gap_analysis`. Phase 0 gave four nullable FKs; `audit` and `gap_analysis` have no BCMS table to point at (an audit finding comes from thirdLine's `issues`, a gap analysis from the maturity engine). One `source` string names the kind; the FKs stay for the four the database can check. |
| `bcms_findings.management_review_id` (FK) | A sixth source, and the reason a management review needs no action register of its own — see below. |

### One column dropped

`bcms_programmes.policy_document_id` is **dropped**. It was created in Phase 0
pointing at a document-control module that does not exist here, nothing has ever
written to it, and leaving it beside `policy_plan_id` would be two columns for
one relationship — the defect ADR 0003 and the `aar_id` decision both already
refused elsewhere. Dropping an unwritten column in the same migration that
replaces it is cheaper now than in Phase 11.

## Two things this ADR deliberately does not add

**A management-review action register.** Actions arising from a management review
are **corrective actions**, raised against a finding whose `source` is
`management_review`. Building a second action register would give a customer two
places to look for "what did we agree to do", and Orchestration §5 makes
`Finding` + `CorrectiveAction` the shared contract precisely so that does not
happen.

**A maturity score on the programme row.** The score lives in
`bcms_maturity_scores` with the assessment that produced it, dated. Denormalising
"current maturity" onto `bcms_programmes` would create a number two things could
write, and the Phase 11 heatmap must read the same rows the board pack printed.

## Consequences

- `bcms:verify-schema` fails until the manifest is regenerated. That is the
  mechanism working: **the migration and the manifest go in one commit.**
- Tracks B, C, D and E: **no column you read has changed.** `bcms_findings`
  gains two columns and `bcms_processes` one; every existing column keeps its
  name, type and meaning. Nothing needs rebasing.
- Track D (Phase 11) inherits `bcms_maturity_*` and `bcms_management_reviews` and
  must build over them. Orchestration §5 already assigns it "no second scorer";
  this is the table that makes that checkable by `grep`.
- The next phase that wants a structural change writes ADR 0009. If that starts
  happening every phase, the freeze has failed and the architect should say so
  rather than keep signing them.
