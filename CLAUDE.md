# riskerm — Atheris ERM

Four modules in one application: **ERM/Risk** (register, assessments, controls, KRIs, appetite),
**RCSA v2**, **TPRM** (third-party risk) and **BCMS** (business continuity). They share a tenancy
layer, a permission catalogue, a navigation tree and a test suite, so a change in one breaks
another more often than you would expect.

`docs/DEVELOPMENT_STANDARD.md` is the product's standard and outranks any plan or build-pack
document. Every entry in it was bought with a shipped defect. Plans live in `plans/`; per-module
notes and ADRs in `docs/`.

## The database gap

**CLOSED ON THIS BRANCH, 2026-09-09.** `phpunit.xml` runs on **MariaDB 10.4** (`risk_test`) and
`ci.yml` is a single `mariadb:10.4` job — the `[sqlite, mysql:8.0]` matrix is gone. Production is
MariaDB 10.4; the hosting panel says "MySQL", the server does not. `main` still has the old
config, so the gap below still describes it.

Closing it took the suite from 0 failures to 72, then to 6. None were regressions: they were
defects SQLite had concealed, including a `connectors.config` json column holding encrypted
ciphertext, which meant creating a connector had **never once succeeded on a real database**.
Every one of the 72 had already been diagnosed and fixed on `migration/phase-7-shared-packages`
months earlier — **diff against that branch before writing any MariaDB-compatibility fix.**

Portable SQL remains a correctness requirement rather than a style preference — the suite now
catches the difference on this branch, but `main` still cannot. Raw JSON
functions, CTEs and window functions are the usual offenders — MySQL 8 has them, MariaDB 10.4
largely does not. `app/Services/Bcms/Exercises/CalendarService.php:410` shows the shape of a good
outcome and states the reason: a raw `JSON_CONTAINS` "would pass every test and fail on the only
database a customer runs".

**When `main` is done, the fix is two files, not one** — that is how it was done here. Switching `phpunit.xml` to MariaDB leaves CI still running its
second leg against `mysql:8.0` — and that arm is the more dangerous of the two, because MySQL 8 has
the CTEs, window functions and full JSON function set that MariaDB 10.4 largely does not. The
sqlite arm is obviously not production and nobody trusts it; the mysql arm looks like real database
coverage and goes green on SQL production cannot run. `ci.yml`'s service image must become
`mariadb:10.4` alongside the `phpunit.xml` change, or CI keeps certifying against a database nobody
ships. See `e24f3b6` for both halves.

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
