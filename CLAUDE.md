# riskerm

## BCMS module work is agent-driven

If this session is doing **Business Continuity (BCMS)** work — branch `feature/bcms-module`,
worktree `riskerm-wt/bcms`, anything under `app/*/Bcms/`, or a phase prompt from the build pack —
read `riskerm-wt/bcms/CLAUDE.md` first and follow it. The build pack sits untracked at
`nexusrisk-bcms-build-pack/` in this checkout and at `plans/bcms/` on the BCMS branch.

The short version, binding from Phase 7 to the end of the module:

- The nine agents in `.claude/agents/` are **not optional**. `architect`, `compliance-analyst`,
  `ui-designer`, `backend-engineer`, `integrations-engineer`, `frontend-engineer` and
  `reliability-engineer` lead or contribute per the RACI in `BCMS-ORCHESTRATION.md` §3.
- `qa-engineer` then `code-reviewer` are **mandatory gates on every phase and every test cycle**.
  The agent that wrote the code does not certify it. Green tests are an input to the gate, not
  the gate. After any defect fix the cycle restarts at `qa-engineer`.
- Every agent ends with the `## HANDOFF` block from §6.

The agents are written against this repository's conventions — `docs/DEVELOPMENT_STANDARD.md` and
ADR 0007 — not the generic Laravel application the build pack assumes. Where the two disagree,
the repository wins.

Agent definitions load at **session start**. Adding or editing one requires a new session before
it can be used.
