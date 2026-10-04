# riskerm — Atheris ERM

Four modules in one application: **ERM/Risk** (register, assessments, controls, KRIs, appetite),
**RCSA v2**, **TPRM** (third-party risk) and **BCMS** (business continuity). They share a tenancy
layer, a permission catalogue, a navigation tree and a test suite, so a change in one breaks
another more often than you would expect.

`docs/DEVELOPMENT_STANDARD.md` is the product's standard and outranks any plan or build-pack
document. Every entry in it was bought with a shipped defect. Plans live in `plans/`; per-module
notes and ADRs in `docs/`.

## The database gap

**Production runs MySQL 8.0.46** (found on the VPS, `/var/www/thirdLine-ERM`, 2026-10-03). The
bank's environment, the developers' machines and this suite's history all assumed **MariaDB
10.4**. Both are real, so **CI runs the suite on both** — `ci.yml`'s `tests` matrix,
`mariadb:10.4` and `mysql:8.0.46`, `fail-fast: false` — and a change is green only when both legs
are. Code is held to the **intersection** of the two engines. `phpunit.xml` serves both unchanged
(`mysql` driver, `risk_test`); locally you run whichever you have; CI runs both.
`Preflight::EXPECTED_DB_ENGINES` names both, and `PreflightDatabaseEngineTest` fails if it drifts
from `ci.yml`'s images.

The SQLite → MariaDB switch (`e24f3b6`, on `main` since PR #9) took the suite from 0 failures to
72, then to 6. None were regressions: they were defects SQLite had concealed, including a
`connectors.config` json column holding encrypted ciphertext, which meant creating a connector had
**never once succeeded on a real database**. Every one of the 72 had already been diagnosed and
fixed on `migration/phase-7-shared-packages` months earlier — **diff against that branch before
writing any MariaDB-compatibility fix.** MySQL 8 had never run the suite before the matrix: the old
`[sqlite, mysql:8.0]` legs died at `composer install` on every run and never reached a test.

**MySQL-8-only SQL is still the trap.** Raw JSON functions, CTEs and window functions — MySQL 8
has them, MariaDB 10.4 largely does not. A MySQL 8 leg on its own goes green on SQL the MariaDB
estate cannot run, which is why MySQL was added as a second leg and not swapped in.
`app/Services/Bcms/Exercises/CalendarService.php:410` shows the shape of a good outcome and states
the reason: a raw `JSON_CONTAINS` "would pass every test and fail on the only database a customer
runs".

**The gap now runs both ways.** MySQL 8 stores `json` natively and hands it back normalised (spaces
added, keys re-ordered), so anything that hashes or string-compares a raw json column passes on
MariaDB and fails on MySQL — `App\Models\Tprm\AuditLog::chainHash` over `tp_audit_logs.before/after`
is the known case. MySQL 8 also defaults `explicit_defaults_for_timestamp` ON (ADR 0022), refuses an
UPDATE or DELETE whose subquery reads the same table (error 1093), and has binary logging ON, so
`CREATE TRIGGER` (`2026_08_09_100004`) needs SUPER or `log_bin_trust_function_creators=1`. CI's
root has SUPER; the production DB user needs one or the other.

## Module work is agent-driven

The nine definitions in `.claude/agents/` are written against **this** repository — its layout,
its conventions, and the defects it has actually shipped — and they cover all four modules, not
one. Each carries a module map and per-module sections for TPRM, RCSA and BCMS.

| | |
|---|---|
| `architect` | schema freezes, numbered ADRs, cross-module contracts, scope-creep refusal |
| `compliance-analyst` | clause maps and evidence sufficiency — ISO 22301, CBN, DORA, PCI 12.8, NDPA |
| `ui-designer` | one screen spec per screen, states and accessibility, before any React |
| `backend-engineer` | models, services, policies, form requests, controllers, jobs, commands |
| `integrations-engineer` | every boundary the product does not control, inbound and outbound |
| `frontend-engineer` | Inertia + React screens against real endpoints, verified in a browser |
| `reliability-engineer` | queues, scheduler, watchdogs, idempotency, load, disaster recovery |
| `qa-engineer` | **gate 1** — tests and acceptance verification |
| `code-reviewer` | **gate 2** — read-only merge veto |

**The two gates are mandatory on every phase and every test cycle.** The agent that wrote the code
does not certify it; green tests are an input to the gate, not the gate. After any defect fix the
cycle restarts at `qa-engineer`. This is binding for BCMS from Phase 7 onward and applies the same
way to TPRM and RCSA phase work. Every agent ends with the `## HANDOFF` block from
`plans/bcms/BCMS-ORCHESTRATION.md` §6.

For BCMS specifically, read `docs/bcms/WORKING-AGREEMENT.md` — the roster with its model policy,
the per-phase sequence and the standing rules from the orchestration document. (It was the root
`CLAUDE.md` of the `riskerm-wt/bcms` worktree until the two branches were merged.)

Agent definitions load at **session start**. Adding or editing one requires a new session before
it can be used.
