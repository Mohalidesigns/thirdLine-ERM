# ADR 0007 — Where BCMS deviates from the blueprint's stated stack, and why

**Status:** Accepted · **Date:** 2026-09-07 · **Phase:** BCMS Phase 0 · **Author:** architect
**Consumers:** all tracks — read before writing the first file of any phase

## Context

The blueprint and the orchestration document were written against a generic
Laravel application. This repository has settled conventions recorded in
`docs/DEVELOPMENT_STANDARD.md`, most of them bought with a defect. Where the
two disagree, the repository wins, and the disagreement is recorded here rather
than discovered file by file.

## The seven deviations

| # | Build pack says | This repository does | Why |
|---|---|---|---|
| 1 | Laravel 11 | **Laravel 12** (`composer.json`, PHP ^8.2) | Nothing in the blueprint depends on 11. |
| 2 | `app/Modules/Bcms/` | **`app/Models/Bcms`, `app/Services/Bcms`, `app/Support/Bcms`, `app/Enums/Bcms`, `app/Http/Controllers/Bcms`, `app/Policies/Bcms`** | There is no `app/Modules` and no module autoloader here. RCSA v2 and TPRM both use the flat layout; a third convention for a third module is a cost with no benefit. Consequence: **no `BcmsServiceProvider`** — routes go in `routes/web.php` behind `feature:bcms`, the morph map into `AppServiceProvider`, the schedule into `routes/console.php`, exactly as TPRM's do. |
| 3 | `tenant_id` on every table | **`organization_id` + `BelongsToOrganization`** | ADR 0006. |
| 4 | UUID keys implied by `id` columns in §9 | **bigint `id` + unique `uuid` on aggregate roots** | House pattern (`risks`, `controls`, `tp_engagements`); `HasObjectIdentity` indexes the object graph by numeric key, so a UUID PK could not be referenced by it. Child/line tables carry no uuid — nothing links to them from outside. |
| 5 | **React 18 + Ant Design 5** | **Inertia React + `@thirdline/ui` + Tailwind; charts are Chart.js** | Development standard §8 and migration Decision 4. Introducing AntD would put two component libraries and two design systems in one navigation tree. The Atheris tokens the phase prompt names (Navy `#1A365D`, Forest `#2D7D46`, Gold `#D4AF37`) are already the product's palette; they are applied through the existing theme, not re-declared. |
| 6 | "activity logging via the existing NexusRisk trait" | **`BcmsAuditable`**, mirroring `TprmAuditable` | There is no single existing trait: the register uses `risk_audit_trail`, TPRM uses `tp_audit_logs`. BCMS follows TPRM — an owned `bcms_audit_logs` table, before/after on `getChanges()` only, secrets excluded, and **auditing never fails the write**. |
| 7 | `php artisan migrate:fresh --seed` as the G0 test | Same command, but the suite is the real gate: **`TenancyIsolationTest`, `RouteAuthorizationTest`, `NoFabricatedNumbersTest`, `PermissionCatalogCoversRoutesTest`** | Development standard §11. Two of those fail in ways that look like success — a plausible number, an empty result — so a green `migrate:fresh` is necessary and nowhere near sufficient. |

## Two things the build pack asks for that this phase deliberately does **not** do

- **Meilisearch and Laravel Prism bindings.** Neither is wired for BCMS in
  Phase 0. Search indexing arrives when there is something to index; wiring it in
  the schema-freeze commit would be an untested dependency.

  **Update, Phase 2: Prism is never coming.** It is not installed in this product
  and never has been. BCMS AI runs on `App\Services\LlmService` — the locally
  hosted, Ollama-compatible client every existing AI feature already uses —
  wrapped in `App\Services\Bcms\Ai\BcmsLlmClient`. **ADR 0010** argues it,
  and the argument is not only reuse: a BIA is a bank's complete map of what it
  depends on and how long it survives without each piece, and an architecture
  that can run entirely inside the customer's estate is the one this product can
  actually sell into Blueprint §14's on-prem requirement.

  **Update, Phase 2: MeiliSearch is not installed either.** Process search is a
  database `LIKE` across name, code and description. Adequate at fifty processes
  and it will not be at five thousand; installing a search stack is an
  infrastructure decision rather than a phase's.
- **A `docs/design/BCMS-BLUEPRINT.md` copy.** The blueprint lives at
  `plans/NexusRisk-BCMS-Module-Blueprint-and-Implementation-Plan.md`, beside
  the TPRM and RCSA plans, and the orchestration document and phase prompts
  live at `plans/bcms/`. One copy, in the place this repository already keeps
  plan documents. Every phase prompt's reference to `docs/design/…` means
  `plans/…` here.
