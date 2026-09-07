# RCSA v2 — the rewritten RCSA module

The build record for the RCSA rewrite specified in
`plans/RCSA_Module_Implementation_Plan.md` (v1.0, 06 September 2026).

One note per phase, in `docs/rcsa-v2/`. Each note records what landed, what was
deviated from and why, and what the phase found that the plan did not know.

| Phase | Scope | Status |
|---|---|---|
| P0 | Foundations: schema, methodology, calculation engine | **Done** — [p0-foundations.md](p0-foundations.md) |
| P1 | RCSA Universe screen | **Done** — [p1-universe.md](p1-universe.md) |
| P2 | Template generator and bulk upload | **Done** — [p2-template-and-import.md](p2-template-and-import.md) |
| P3 | Cycles and assessment workspace | **Done** — [p3-cycles-and-workspace.md](p3-cycles-and-workspace.md) |
| P4 | Appetite, action plans, submission gate | **Done** — [p4-appetite-and-submission.md](p4-appetite-and-submission.md) |
| P5 | ORM review workflow, action-plan register | **Done** — [p5-orm-review.md](p5-orm-review.md) |
| P6 | Bulk download, dashboards, offline round-trip | **Done** — [p6-export-dashboards-roundtrip.md](p6-export-dashboards-roundtrip.md) |
| P7 | RBAC, audit, business-unit scoping | **Done** — [p7-rbac-audit.md](p7-rbac-audit.md) |
| P8 | Migration and cutover off the legacy module | **Done** — [p8-migration-and-cutover.md](p8-migration-and-cutover.md) · [runbook](cutover-runbook.md) |
| P9 | Hardening and UAT | Not started |

## The two RCSA modules

Until cutover there are two, and they are unrelated in the code:

- **Legacy** — `app/Http/Controllers/Risk/RcsaController.php`,
  `app/Services/Rcsa/{RcsaService,RcsaWorksheetService}.php`,
  `resources/js/Pages/Rcsa/*`. Four screens computed over `Risk`, `Control` and
  `RiskControlMapping` plus a write into the campaign tables. It has no tables
  of its own. Built in migration Phase 3.8 — see
  `docs/migration/phase-3-notes/rcsa.md`. **It stays live and untouched.**
- **v2** — everything under the `rcsa_` table prefix, `App\Models\Rcsa\*`,
  `App\Services\Rcsa\RcsaCalculationService`, `App\Support\Rcsa\*`. Behind the
  `rcsa_v2` feature flag, default off.

The plan's §13 is the rule: build alongside, not on top. No legacy table is
dropped and no legacy route redirects until a tenant has completed a parallel
run and signed off the reconciliation.

**P8 established that the legacy module has no tables of its own**, which
changes what §13's "legacy tables become read-only" can mean: `risks` and
`controls` are the ENTERPRISE register that half the product reads, and locking
them would take the Risk Register, KRI, the control library and the board pack
down with it. What closes at cutover is the one legacy WRITE PATH —
`risk.rcsa.worksheet.store`. See [the runbook](cutover-runbook.md).

## Open decisions

§14 of the plan lists ten questions for the bank. Three of them block P3 and are
carried in the schema as configuration rather than being guessed at:

| # | Question | Where it is parked |
|---|---|---|
| Q1 | Is residual always calculated, or may an assessor override it? | `rcsa_methodologies.residual_mode` — `calculated` seeded |
| Q3 | Should Fully Achieved really drive residual to zero? | `rcsa_methodologies.residual_floor` — `0` seeded, which is template parity |
| Q4 | Is appetite a single ceiling, or a statement per risk category? | `rcsa_methodologies.appetite_ceiling_level` — `low` seeded |

Q1 is now visible in the product: `residual_mode` is `calculated`, so the workspace offers no residual likelihood/impact pair and the line endpoint refuses one. Switching the seeded methodology to `assessed` turns both on. The remaining questions (Q2, Q5–Q10) do not block P0–P3.

**Q6 is now answered in configuration.** The BU-head approval step is
`organizations.settings['rcsa']['bu_approval_required']`, default off — a bank
that wants it turns it on and takes `rcsa_assessment.submit` off `risk-owner`
if it wants the champion unable to file directly. **Q8 (who assigns assessors) is now answerable**: P7 built
`business_unit_user`, which is the model an assignment screen would read.
`reviewer_id` is written when a reviewer claims an assessment; `assigned_to` is
still null, and a screen for it is P8 or later.

## Fixes found in use

| Reported | Note |
|---|---|
| "CRO gets 403 Unauthorized action on RCSA" (during P2) | It was `super-admin`, not the CRO, and the cause predates RCSA v2 — [super-admin-403.md](super-admin-403.md) |
| "admin@risk.test can't log in" — 419 PAGE EXPIRED | The accounts were fine; all six seeded demo logins verified. An expired token on the login page was a dead end, and now recovers — [login-419.md](login-419.md) |

## Carried forward

| From | Item |
|---|---|
| P2 | Reject an upload whose `tenant_id` marker names another organisation. Both markers are written and asserted; the refusal rule is not wired, because it must not refuse the CSVs and hand-built files the pipeline is meant to accept. See p2's deviation 1. |
| P0 | Diff `resources/js/lib/rcsa-truth-table.json` against the `SB_RCSA Template 2026` workbook. Still the one input to this build that has not been checked against its source. |
