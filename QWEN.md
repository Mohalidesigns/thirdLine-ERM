# riskerm — Atheris ERM (Qwen Code)

@CLAUDE.md

Everything imported above is the project's context, and it is the same whichever tool reads it.
What follows is only the Qwen Code side of it.

## The agent roster lives in two places

`.claude/agents/` and `.qwen/agents/` hold the same nine definitions, paired one file per name.
Qwen Code loads `.qwen/agents/`; Claude Code loads `.claude/agents/`. **Change one, change the
other.** The bodies are meant to stay identical — only the frontmatter differs:

| Claude Code frontmatter | Qwen Code frontmatter |
|---|---|
| `model: opus` | `model: inherit` — the session model |
| `model: sonnet` | `model: fast` — the configured `fastModel`, or `inherit` while none is set |
| `tools: Read, Write, Edit, Glob, Grep, Bash` | a YAML list of `read_file`, `write_file`, `edit`, `glob`, `grep_search`, `run_shell_command` |
| `WebSearch`, `WebFetch` | `web_search`, `web_fetch` |

Two bodies do differ on purpose, because they named Claude-only capabilities: `code-reviewer`
(`Bash` → `run_shell_command`) and `frontend-engineer` (the browser section — see below).

Definitions load at **session start**. Adding or editing one requires a new session before it can
be used.

## Invoking the two gates

`qa-engineer` (gate 1) then `code-reviewer` (gate 2) are mandatory on every phase and every test
cycle, and the agent that wrote the code certifies nothing:

```text
agent(subagent_type="qa-engineer", description="Gate 1: <phase>", prompt="…", run_in_background=false)
```

`run_in_background: false` keeps a gate inline. Named subagents otherwise run in the background and
report after you have already moved on, which is the opposite of what a gate is for. After any
defect fix the cycle restarts at `qa-engineer`.

`code-reviewer` is given no `edit` or `write_file` tool, so its read-only veto is enforced by its
tool list and not only by its instructions.

## Three ways this differs from Claude Code

- **The browser has to be approved on each machine.** Qwen Code has no browser pane; front-end
  verification runs through the Playwright MCP server declared in `.qwen/settings.json`, pinned to
  `@playwright/mcp@0.0.84`. It is project-scoped, so a fresh clone must run
  `qwen mcp approve playwright` and then start a new session before any of its tools exist.
  `frontend-engineer` names the 24 tools it may call — `browser_run_code_unsafe` is deliberately not
  one of them. Until the server reports Connected, say so in the handoff rather than claiming a
  screen was verified.
- **Web search is not guaranteed.** `web_search` is a built-in tool but it sits behind the
  `webSearch` settings group and is billed separately by DashScope. Check `/tools` before an agent
  relies on it; `web_fetch` on a known URL always works.
- **Nothing reads `.claude/launch.json`.** The three dev-server recipes in it — `php artisan serve`
  behind an `sh -c` wrapper, and two `php -S` configs against the Docker MySQL 8 container — are
  still the tested way to serve this app, so run them with `run_shell_command` rather than assuming
  a harness will start the server for you. The comments in that file explain why each command is
  shaped the way it is.
