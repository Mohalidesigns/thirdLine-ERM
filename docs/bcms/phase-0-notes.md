# BCMS Phase 0 — foundations and schema freeze

**Gate:** G0 · **Track:** T0, single track · **Lead:** architect

What was built, what was decided, and what the next four tracks are entitled to
rely on. Written in the shape of `docs/migration/phase-*-notes/` because that is
where a reader of this repository looks for the reasoning behind a phase.

---

## 1. What landed

| Layer | Files |
|---|---|
| Plan documents | `plans/bcms/BCMS-ORCHESTRATION.md`, `plans/bcms/prompts/PHASE-*.md`, `plans/bcms/BUILD-PACK-README.md` (blueprint stays at `plans/NexusRisk-BCMS-Module-Blueprint-and-Implementation-Plan.md`) |
| Agents | `.claude/agents/compliance-analyst.md`, `integrations-engineer.md`, `reliability-engineer.md` |
| ADRs | `docs/adr/0001`–`0007` |
| Compliance | `docs/compliance/iso22301-clause-map.md`, `cbn-obligations.md`, `ndpa-register.md` |
| Performance | `docs/performance/baseline.md` |
| Schema | 8 migrations, **53 tables**, plus `database/schema/bcms-manifest.php` |
| Enums | `app/Enums/Bcms/` — 15, including the 52-case `IsoClauseRef` |
| Models | `app/Models/Bcms/` — 52 models and 3 concerns |
| Factories | `database/factories/Bcms/` — 50 |
| Contracts | `app/Contracts/Bcms/` — `NotificationChannel`, `Recipient`, `RenderedMessage`, `DeliveryReceipt` |
| Services | `AudienceResolver`, `ContactResolver`, `BcmsSettings`, `Notification\ChannelRegistry`, 9 mock channel adapters |
| Support | `app/Support/Bcms/AudienceRule.php`, `ModuleSections.php` |
| Commands | `bcms:verify-schema`, `bcms:dispatch-reminders` (skeleton), `bcms:watchdog` |
| Config | `config/bcms.php`; four supervisors added to `config/horizon.php`; `features.bcms` |
| Permissions | 55, in `RiskPermissionCatalog`, granted to six roles, plus a grant migration for deployed tenants |
| Seeders | `BcmsReferenceSeeder` (7 system libraries), `BcmsDemoSeeder` |
| HTTP | routes behind `feature:bcms`, `HomeController`, `SectionController`, `SettingsController`, `BcmsHomePresenter`, one form request |
| Front end | `resources/js/Pages/Bcms/{Home,Section,Settings}.jsx`, nav section |
| Tests | `tests/Feature/Bcms/` — `Phase0FoundationsTest` (21), `ModuleShellTest` (16), `CrossTrackContractsTest` (23) |

## 2. Gate G0 — the eight criteria

| # | Criterion | Status |
|---|---|---|
| 1 | `migrate:fresh --seed` green on a fresh tenant | **Pass** — verified on MySQL 8 and on the SQLite the suite uses |
| 2 | Every §9 table exists; `bcms:verify-schema` exits 0 | **Pass** — the command diffs the live schema against the frozen manifest and fails in **both** directions |
| 3 | Module in nav for permitted roles only; 403 without `bcms.view` | **Pass** — `ModuleShellTest`, including 404 when the flag is off |
| 4 | Settings persist and are read back **by a service** | **Pass** — `BcmsSettings`, asserted at the service and the HTTP boundary |
| 5 | Exercise types, blackout calendar and clause refs seed for a new tenant | **Pass** — and the system-owned visibility trap is asserted explicitly |
| 6 | The morph map resolves all seven dependency types, round-tripped | **Pass** |
| 7 | Four supervisors; life safety picked up under a 10,000-job backlog | **Partial, and stated as such.** The four supervisors, their separate queues and life safety's warm-worker floor are asserted from configuration. A real Redis backlog is a Phase 12 load test; `docs/performance/baseline.md` records the same limitation rather than claiming a pass |
| 8 | Cross-tenant read test exists and fails closed on core models | **Pass** — plus the repository-wide `TenancyIsolationTest`, which now covers the BCMS models with no opt-in |

## 3. The decisions a later phase must not re-litigate

Each is an ADR; this is the index.

1. **BCMS owns `bcms_*` and nothing else.** No migration touches a table it does
   not own. Every reach outward is a nullable FK from a `bcms_` table (ADR 0001).
2. **Four seam registers** — `bcms_sites`, `bcms_applications`, `bcms_equipment`,
   `bcms_data_sets` — exist only because no upstream module owns them. Named
   debt, with `external_ref` and the morph map as the repointing seam.
3. **One morph map for the whole application**, in `App\Support\MorphTypes`, with
   `bcms_*`-prefixed singular aliases. `users` was not added because `user`
   already exists and two aliases for one class is a coin toss written into
   customer data (ADR 0002).
4. **One audience grammar, one resolver, resolving to contacts** (ADR 0003).
5. **`NotificationChannel` frozen with a mock for every channel**, so Track B
   reaches Gate G1 in Week 8 with no provider contract signed (ADR 0004).
6. **The reminder ladder is materialised, one row per intended send**, with a
   database-enforced unique idempotency key (ADR 0005).
7. **`organization_id` + `BelongsToOrganization`**, and org scoping delegates to
   the existing `RcsaScope` engine rather than reimplementing the walk (ADR 0006).
8. **Seven deviations from the build pack's stated stack**, including no
   `app/Modules`, no Ant Design, and Laravel 12 (ADR 0007).

## 4. Deviations from the phase prompt, each argued where a reader hits it

| Prompt asks for | What was built | Why |
|---|---|---|
| `app/Modules/Bcms/` and a `BcmsServiceProvider` | Flat layout; routes in `routes/web.php`, morph map in `AppServiceProvider`, schedule in `routes/console.php` | No `app/Modules` and no module autoloader here. A third convention for a third module is cost with no benefit (ADR 0007) |
| React + Ant Design 5 | Inertia React + `@thirdline/ui` + Tailwind | Two component libraries in one navigation tree. The Atheris palette is already the product's (ADR 0007) |
| `bcms_exercise_occurrences.aar_id` | Only `bcms_aars.occurrence_id`, unique | Two pointers at one relationship can disagree and nothing in the database stops them |
| Demo tenant "Kano Heritage Bank" as a new organisation | The BCMS estate grown on the existing demo organisation, with Kano Heritage Bank as the trading name on the programme | A second organisation would demo a bank with no risk register, no vendors and no KRIs. An integrated suite has to demo as one tenant |
| "Activity logging via the existing NexusRisk trait" | `BcmsAuditable` and `bcms_audit_logs`, mirroring `TprmAuditable` | There is no single existing trait — the register uses `risk_audit_trail`, TPRM uses `tp_audit_logs` |
| Meilisearch and Prism wiring | Neither | An untested dependency in the schema-freeze commit. Both arrive with the phases that own them |

## 5. Known gaps handed to later phases

1. ~~**Management review (ISO 22301 9.3) has no home table.**~~ **Closed in
   Phase 1**, sooner than this note expected — Phase 1's acceptance criterion 7
   required clause 9.3 to be evidenced, so ADR 0008 arrived in week 2 rather
   than week 12. The prediction was right about everything except the date, and
   the freeze behaved exactly as designed: `bcms:verify-schema` named all
   fifteen changes, the ADR argued each, and the manifest was regenerated in the
   same commit. See `docs/bcms/phase-1-notes.md` §3.
2. **`HasObjectIdentity` is not applied to any BCMS model.** The object graph
   would need type registration in `ObjectSourceMap`, which is a Phase 11
   reporting concern and not a Phase 0 one. No BCMS row is in the graph index.
3. **`App\Support\Rcsa\RcsaScope` is now used by two modules and its namespace is
   wrong for what it does.** Renaming it is a refactor of RCSA's call sites, not
   a Phase 0 decision. Revisit when a third module needs it. (Phase 1 exercised
   it in anger — the process catalogue's org scoping runs through it and is
   tested in both directions — and it held.)
4. **Geo columns exist and nothing writes them.** Before anything does, Phase 2C
   must add a separate location consent, a granularity statement and a retention
   in days — see the NDPA register.
5. **Every notification channel is a mock.** The home screen and the settings
   screen both say so. Phase 7 swaps them one at a time through
   `config('bcms.channels')`.
6. **`bcms:dispatch-reminders` reports and dispatches nothing.** Shipping a
   half-dispatcher that sends some traffic would be worse than shipping none.

## 6. HANDOFF

**Phase:** P0 — Foundations & schema freeze
**Agent:** architect (lead), with compliance-analyst, backend-engineer, reliability-engineer, frontend-engineer
**Status:** complete
**Delivered:** as §1 above
**Contracts touched:** every contract in Orchestration §5 — all frozen at this commit: `bcms_contacts` + `ContactResolver`, the `AudienceRule` schema, the `NotificationChannel` interface **and its mock adapters**, the `Finding`/`CorrectiveAction` core (schema only; Track A owns the service), the `bcms_dependencies` morph map, the `iso_clause_ref` taxonomy, and the org-scoping trait
**Assumptions made:** the six in §4 and the three at the foot of the clause map
**Known gaps:** the six in §5
**Next agent:** Track A opens with `plans/bcms/prompts/PHASE-01-programme-governance.md`; Tracks B and C start from this commit. **Track A ships the `Finding` + `CorrectiveAction` service and register in Week 3, before any producer needs it** — Orchestration §5 makes that a hard ordering, not a preference
**Verification run:** `php artisan test tests/Feature/Bcms` — 60 passed; the eight load-bearing guards (`RouteAuthorizationTest`, `NoFabricatedNumbersTest`, `PermissionCatalogCoversRoutesTest`, `AdminNavigationTest`, `TenancyIsolationTest`, `AuditActionTypeWidthTest`, `ObjectIdentityTest`, `PreflightRouteGuardTest`) — 446 passed; `php artisan migrate --seed` green on MySQL 8; `php artisan bcms:verify-schema` exit 0; `npm run build` green
