# riskerm — Atheris ERM

Four modules in one application: **ERM/Risk** (register, assessments, controls, KRIs, appetite),
**RCSA v2**, **TPRM** (third-party risk) and **BCMS** (business continuity). They share a tenancy
layer, a permission catalogue, a navigation tree and a test suite, so a change in one breaks
another more often than you would expect.

`docs/DEVELOPMENT_STANDARD.md` is the product's standard and outranks any plan or build-pack
document. Every entry in it was bought with a shipped defect. Plans live in `plans/`; per-module
notes and ADRs in `docs/`.

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

For BCMS specifically, read `riskerm-wt/bcms/CLAUDE.md` — the roster, the per-phase sequence and
the standing rules from the orchestration document.

Agent definitions load at **session start**. Adding or editing one requires a new session before
it can be used.
