# RCSA v2 — the rewritten RCSA module

The build record for the RCSA rewrite specified in
`plans/RCSA_Module_Implementation_Plan.md` (v1.0, 06 September 2026).

One note per phase, in `docs/rcsa-v2/`. Each note records what landed, what was
deviated from and why, and what the phase found that the plan did not know.

| Phase | Scope | Status |
|---|---|---|
| P0 | Foundations: schema, methodology, calculation engine | **Done** — [p0-foundations.md](p0-foundations.md) |
| P1 | RCSA Universe screen | **Done** — [p1-universe.md](p1-universe.md) |
| P2 | Template generator and bulk upload | Not started |
| P3 | Cycles and assessment workspace | Not started |
| P4 | Appetite, action plans, submission gate | Not started |
| P5 | ORM review workflow | Not started |
| P6 | Bulk download, dashboards, offline round-trip | Not started |
| P7 | RBAC, audit and notifications | Not started |
| P8 | Migration and cutover off the legacy module | Not started |
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

## Open decisions

§14 of the plan lists ten questions for the bank. Three of them block P3 and are
carried in the schema as configuration rather than being guessed at:

| # | Question | Where it is parked |
|---|---|---|
| Q1 | Is residual always calculated, or may an assessor override it? | `rcsa_methodologies.residual_mode` — `calculated` seeded |
| Q3 | Should Fully Achieved really drive residual to zero? | `rcsa_methodologies.residual_floor` — `0` seeded, which is template parity |
| Q4 | Is appetite a single ceiling, or a statement per risk category? | `rcsa_methodologies.appetite_ceiling_level` — `low` seeded |

The remaining seven (Q2, Q5–Q10) do not block P0 or P1.
