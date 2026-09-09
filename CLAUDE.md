# BCMS module — working agreement

Branch `feature/bcms-module`, worktree `riskerm-wt/bcms`.

- **Specification:** `plans/NexusRisk-BCMS-Module-Blueprint-and-Implementation-Plan.md`
- **Plan:** `plans/bcms/BCMS-ORCHESTRATION.md` · **Phase prompts:** `plans/bcms/prompts/`
- **Conventions:** `docs/DEVELOPMENT_STANDARD.md` and the ADRs in `docs/adr/` — read **ADR 0007**
  first; it lists the seven places the build pack's stack disagrees with this repository, and in
  every case the repository wins. Where a phase prompt says `docs/design/…`, it means `plans/…`.
- **Phase notes:** `docs/bcms/phase-N-notes.md`, one per completed phase.

## Agents are mandatory from Phase 7 onward

Phases 0–6 were built serially in one session without subagents. From Phase 7 to the end of the
module this is no longer allowed. The definitions live in `.claude/agents/` and are loaded at
**session start** — a session that began before an agent file existed cannot see it, so a new
agent means a new session.

| Agent | Model | Role on the remaining phases |
|---|---|---|
| `architect` | opus | Schema freeze, numbered ADRs in `docs/adr/`, cross-track contracts, module boundaries, scope-creep enforcement. Consulted before any phase that wants a column. |
| `compliance-analyst` | opus | **Leads P11.** Clause maps, `iso_clause_ref` taxonomy, CBN/NDPA obligations, AAR rules per ISO 22398, shipped content packs. Consulted before build on every phase; verifies evidence sufficiency after QA. |
| `ui-designer` | sonnet | Screen specs to `docs/bcms/screens/` before any React is written — states, interactions, WCAG 2.1 AA, 100 kbps behaviour. |
| `backend-engineer` | sonnet | **Leads P9 and P10.** Models, services, policies, form requests, controllers, jobs, commands, Inertia payloads. |
| `integrations-engineer` | sonnet | **Leads P7.** SMS/WhatsApp/voice/USSD gateways, LDAPS/Entra/SCIM, provider failover and cost accounting, inbound webhooks. |
| `frontend-engineer` | sonnet | Screens under `resources/js/Pages/Bcms/`, against the real API — never mocks. Verifies in `npm run build` and a browser, because no JavaScript runs in the test suite. |
| `reliability-engineer` | opus | **Leads P12.** Queue topology, Horizon supervisors, scheduler and watchdog, idempotency, write-ahead dispatch, load testing to the §14 NFRs, self-DR runbook. Also **R** on P7. |
| `qa-engineer` | sonnet | **Gate 1 on every phase and every test cycle.** Never skippable. |
| `code-reviewer` | opus | **Gate 2 on every phase.** Read-only veto. Never approves its own work. |

Model policy follows `plans/bcms/BCMS-ORCHESTRATION.md` §2: Opus for the judgment-heavy roles — architect,
compliance-analyst, reliability-engineer, code-reviewer — Sonnet for the execution roles.

All nine are written against **this repository**, not the generic Laravel application the build
pack assumes. Where the two disagree — AntD vs `@thirdline/ui` + Tailwind, `tenant_id` vs
`organization_id`, `app/Modules` vs the flat layout, Prism vs `LlmService` — the agents follow
`docs/DEVELOPMENT_STANDARD.md` and ADR 0007, and so should you.

They are also **product-wide, not BCMS-only**: each carries a module map and per-module sections
for TPRM and RCSA alongside BCMS, because the four modules share a tenancy layer, a permission
catalogue and one test suite. Use the same nine, and the same two gates, on TPRM and RCSA work.

## The per-phase sequence

```
architect / compliance-analyst  →  spec, ADR, clause map committed to docs/
        ↓
ui-designer                     →  screen spec per screen in docs/bcms/screens/
        ↓
backend-engineer  ‖  integrations-engineer   →  API, services, jobs
        ↓
frontend-engineer               →  screens against the real API, never mocks
        ↓
reliability-engineer            →  (P7 and P12 only) queues, load, failure modes
        ↓
qa-engineer     →  MANDATORY GATE. Tests + acceptance verification.
        ↓
code-reviewer   →  MANDATORY GATE. Read-only. Merge or reject.
```

If QA fails, the phase returns to the implementing agent with a numbered defect list. It does
**not** proceed to review, and the implementer does not mark itself done.

## Every test cycle goes through the agents

A "test cycle" is any run of the suite intended to establish that something works — the phase
gate, a re-test after a fix, a regression pass before a commit, or a pre-merge check. Every one
of them is `qa-engineer` then `code-reviewer`. Specifically:

- The main session does not self-certify a phase by running `php artisan test` and reporting green.
  Green is an input to the gate, not the gate.
- After any defect fix, the **same** cycle runs again from `qa-engineer`. A fix is not verified by
  the agent that wrote it.
- `qa-engineer` runs the **full** suite for a verdict, not a filtered subset — a BCMS change that
  breaks ERM, RCSA or TPRM is a failed gate.
- `code-reviewer` re-runs the suite itself rather than trusting the handoff.

## Handoff block

Every agent ends its output with the `## HANDOFF` block from `plans/bcms/BCMS-ORCHESTRATION.md` §6:
phase, agent, status, delivered, contracts touched, assumptions made, known gaps, next agent,
verification run. This is what keeps the pipeline coherent across sessions.

## Standing rules

`plans/bcms/BCMS-ORCHESTRATION.md` §8 applies to every agent and every session. The load-bearing ones:
no structural migrations after the freeze without a numbered ADR in `docs/adr/`; never write back
to Active Directory; AI output is always an editable draft flagged `ai_generated`; exercise-linked
alerts default to simulation mode with the "THIS IS AN EXERCISE" prefix; life-safety traffic uses
the `bcms-lifesafety` queue and is never throttled; one reminder digest per user per day; persist
before you dispatch; reuse EA/TPRM/KRI/thirdLine rather than duplicating them.

**Three databases, and none of them is the one the suite runs on.** `phpunit.xml:41-42` sets
`DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`; CI runs a `[sqlite, mysql]` matrix against
**MySQL 8.0**; production is **MariaDB 10.4**. Nothing in the pipeline touches MariaDB, so a green
suite is not evidence about the customer's database. The MariaDB test config exists on
`migration/phase-7-shared-packages` and `fix/parent-cycle-guard` and has not reached this branch.
Until it does, portable SQL is a correctness requirement, not a style preference — see
`CalendarService.php:410`, where a raw `JSON_CONTAINS` was deliberately avoided because it "would
pass every test and fail on the only database a customer runs".
