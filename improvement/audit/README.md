# NexusRisk IRM — Pre-Demo Audit Deliverables

Date of pass: **2026-04-22**.
Stack verified: Laravel 12 + MySQL `risk` DB on :8765, Ollama `granite4:micro` at localhost:11434.

## Index

1. [AUDIT_LOG.md](AUDIT_LOG.md) — pages visited, blockers fixed, remaining minor issues
2. [AI_INTEGRATION.md](AI_INTEGRATION.md) — per-feature endpoint, prompt, output sample, latency, fallback
3. [DEMO_SCRIPT.md](DEMO_SCRIPT.md) — 25-minute click-path for a Nigerian bank C-suite audience
4. [COMPETITIVE_NOTES.md](COMPETITIVE_NOTES.md) — one-liner answers for Archer / MetricStream / LogicGate / ServiceNow comparisons

## TL;DR

- **All 72 primary routes return HTTP 200.** No 500s, no Laravel exception markers anywhere in the app.
- **8 blocker-grade UX bugs fixed** — mostly controller/view variable-name drift that rendered dashboards as zeros despite real DB data (KRI, Loss Events, Treatment Plans, RCSA), plus an approvals view authored in Bootstrap instead of Tailwind, plus a Carbon 3 float-days regression in Regulatory.
- **Demo data topped up** so the Command Centre shows demo-grade tension: 4 Critical residual risks, 3 Red KRI breaches, 2 overdue treatment plans, 8 open issues, ₦278 M YTD net loss.
- **AI wired to the local Granite 4 model** for four features so far: Risk Statement Builder (UI-wired), Control Recommender, KRI Suggester, Executive Narrative. Response caching makes the demo's first click instant when pre-warmed.
- **Pre-demo checklist**: run `php artisan llm:warm` then `php artisan ai:warm-cache` ~15 minutes before the client arrives.

## Files changed in this pass

### Added
- `app/Services/LlmService.php` — on-prem LLM HTTP client with timeout, JSON mode, fallback, 24 h cache
- `app/Http/Controllers/Risk/AiToolsController.php` — 4 live-LLM endpoints + health check
- `app/Console/Commands/WarmLlm.php` — artisan `llm:warm`
- `app/Console/Commands/WarmAiCache.php` — artisan `ai:warm-cache`
- `improvement/audit/*` — this deliverables pack

### Modified
- `config/services.php` — `llm` block
- `.env` — `LLM_*` config, `PHP_CLI_SERVER_WORKERS=4`
- `.claude/launch.json` — `--no-reload` to let workers register
- `routes/web.php` — AI Tools routes
- `resources/views/risk/register/create.blade.php` — AI Risk Statement Builder banner + Alpine component
- `resources/views/risk/approvals/dashboard.blade.php` — Bootstrap → Tailwind rewrite
- `resources/views/risk/approvals/history.blade.php` — Bootstrap → Tailwind rewrite
- `resources/views/risk/regulatory/dashboard.blade.php` — days-left int cast
- `app/Http/Controllers/Risk/KriController.php` — flat dashboard vars
- `app/Http/Controllers/Risk/LossEventController.php` — flat dashboard vars
- `app/Http/Controllers/Risk/TreatmentPlanController.php` — flat dashboard vars
- `app/Http/Controllers/Risk/RcsaController.php` — flat dashboard vars
- `app/Http/Controllers/Risk/RiskAppetiteController.php` — appetite metrics + chart data
- `app/Http/Controllers/Risk/IssueController.php` — ageing matrix + trend data

## What was NOT done

- The remaining 8 AI wish-list items (Inherent Scoring Assistant, Adequacy Reviewer, Incident Classifier, Regulatory Mapper, NL Query, Audit Anomaly, Aggregation Commentary, Control Testing Helper) are backend-scaffold-ready but not implemented. Each is a ~1-hour lift on the same pattern.
- Control Recommender and KRI Suggester have working endpoints but are not UI-wired to a button on the Risk Detail page yet. The endpoints are called-callable from browser dev console for the demo if needed, but the nice button is future work.
- Export endpoints (CSV / PDF / Basel / CBN ORMS) were route-listed (200 OK) but not content-inspected — they may work, or may also have variable-drift bugs we haven't surfaced.
- Create/update form submissions were not end-to-end tested beyond the Risk Register create path. A form that 200s on GET can still 500 on POST.
- The four original "AI Intelligence" dashboard pages (Predictive / Radar / Regulatory Pulse / Benchmarking) still render from the `mt_rand()`-backed `AiDataService`. They look convincing, but they are not actually grounded in LLM output. Replacing them is the next iteration.
