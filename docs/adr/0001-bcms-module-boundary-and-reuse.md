# ADR 0001 — BCMS module boundary and the reuse rule

**Status:** Accepted · **Date:** 2026-09-07 · **Phase:** BCMS Phase 0 · **Author:** architect
**Supersedes:** nothing · **Consumers:** every BCMS track

## Context

BCMS is the fifth module to land in this product after ERM, KRI, RCSA v2 and
TPRM. Two of those four had to answer the same question first, and answered it
the same way: **build alongside, not on top.** RCSA v2 built `rcsa_*` tables
beside the register rather than reshaping `risk_assessments`; TPRM built `tp_*`
tables beside them again, reaching ERM through a documented one-way bridge.
The reason is recorded in `config/features.php` and it has not changed: a
module that reshapes an existing table to fit its own workflow makes every
other module's assumptions wrong, silently, on a customer's data.

BCMS is a harder case than either, because Blueprint §4.2 lists eight existing
capabilities it is supposed to consume, and **three of the eight do not exist
in this repository**:

| Blueprint §4.2 says | Reality here |
|---|---|
| Org hierarchy / Business HQ | `entities`, `business_units` — exists |
| Risk register | `risks`, `risk_categories` — exists |
| KRI Collection Module | `key_risk_indicators`, `kri_measurements` — exists |
| TPRM module (vendors) | `tp_third_parties`, `tp_engagements` — exists |
| **Enterprise Architecture module** (applications, infrastructure) | **does not exist** |
| Dashboards builder | `dashboards`, `widget_definitions` — exists |
| Document control | partial — `tp_documents` is TPRM-scoped |
| thirdLine (audit findings) | `issues` — exists |

## Decision

**1. BCMS owns `bcms_*` tables and nothing else.** No BCMS migration alters a
table it does not own. Every reach into another module is a nullable foreign
key from a `bcms_` table outward, never a column added to `risks`,
`business_units`, `key_risk_indicators` or `tp_engagements`.

**2. The bridges are one-way, BCMS → ERM,** with a single stated exception to
be built in Phase 1 (finding ↔ issue close-sync, mirroring the rule TPRM
settled for `tp_findings.erm_issue_id`). Two engines writing one number produce
a number neither can explain.

**3. Where a blueprint dependency does not exist, BCMS creates the minimal
register it needs and marks it as a seam,** not as the authoritative home. Four
such registers are created in Phase 0 — `bcms_sites`, `bcms_applications`,
`bcms_equipment`, `bcms_data_sets` — and each carries a nullable
`external_ref` column plus a header saying which module is expected to take
ownership later. The morph map (ADR 0002) is the seam: when an EA module
lands, `applications` is repointed at its model and the dependency rows follow,
because they store a morph key and an id, not a table name.

**4. `bcms_processes` is an overlay, not a second process catalogue.** It
carries a nullable `business_process_id` to `business_processes`. The BCM
attributes — `criticality_tier`, `is_critical_service`, `regulatory_flags`,
MTPD/RTO/RPO through the BIA — are BCM facts about a process, not facts the
process catalogue should be forced to carry. A tenant that has populated
`business_processes` links; a tenant that has not can still run a BIA.

**5. Resilience metrics are registered as KRIs, never as a parallel metrics
engine.** Blueprint §4.2 is explicit and Orchestration §5 assigns registration
to the phase that produces the metric. Phase 11 aggregates and displays; it
registers nothing that already exists.

## Consequences

- A reviewer can reject any BCMS migration that touches a non-`bcms_` table on
  sight, with no further argument needed.
- The four seam registers are technical debt **on purpose**, and the debt is
  named. They are small (a code, a name, an org node, a criticality) precisely
  so that replacing them is cheap.
- BCMS cannot assume an application inventory exists. Every screen that reads
  one must render an empty state, not a zero. (Development standard §5: a rate
  over nothing is undefined, not zero.)
