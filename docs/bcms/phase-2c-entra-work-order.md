# BCMS Phase 2C (reduced) — Entra ID identity sync · work order

**Track:** C · **Lead:** integrations-engineer · **Decided by:** ADR 0018
**Branch:** `integration/bcms-remaining` · **Schema:** 3 tables, 1 column, 1 unique index (ADR 0018 §2)
**Prompt:** `plans/bcms/prompts/PHASE-02C-identity-ad-entra.md` — **reduced**; read ADR 0018 §1 first for what is in and what is Phase 2D's.

Read before writing a line: `docs/DEVELOPMENT_STANDARD.md`, `docs/adr/0018-*`,
`docs/adr/0007-*`, `docs/adr/0017-*`, `docs/adr/0014-*`,
`plans/bcms/BCMS-ORCHESTRATION.md` §5 and §8, `docs/bcms/phase-6-notes.md` §2
and `docs/bcms/phase-7-notes.md` §5 and §8.

---

## 1. Sequence

`ui-designer` → `compliance-analyst` (NDPA, in parallel) → **`integrations-engineer` (lead)**
→ `backend-engineer` → `reliability-engineer` → `frontend-engineer`
→ **`qa-engineer` (gate 1)** → **`code-reviewer` (gate 2)**.

Two gates, mandatory, no self-approval; after any defect fix the cycle restarts
at `qa-engineer`. The architect does not approve this phase.

**Boundary between the two implementing agents.** integrations-engineer owns
`app/Services/Bcms/Identity/*` — the client, the token, the paging, the delta
cursor, the change detector, the error classification. backend-engineer owns the
models, the enums, the form requests, the controllers, the apply path, the
permissions and the seeder. The interface in §3 is the seam; write it first and
both can work against it.

---

## 2. Migration — one file, and the manifest in the same commit

`database/migrations/2026_09_XX_120001_create_bcms_identity_tables.php`

Columns exactly as ADR 0018 §2.2–2.4. Non-negotiables, each of which has already
cost this product something:

- **`client_secret` is `text`, never `json`.** `encrypted` produces a base64
  envelope; MariaDB's inline `json_valid()` CHECK rejects it. This is the
  `connectors.config` defect — `2026_09_07_130001_store_connector_config_as_text_not_json.php`
  is the post-mortem, and it says every insert into `connectors` had failed on a
  real database since the table existed. `attribute_map`, `before_json`,
  `after_json` and `impact_json` **are** json and are not encrypted.
- **Portable SQL only.** No CTE, no window function, no raw JSON function.
  MySQL 8 has them and MariaDB 10.4 largely does not, and production is MariaDB
  10.4. `app/Services/Bcms/Exercises/CalendarService.php:410` is the worked
  example of the right outcome.
- `manager_contact_id` on `bcms_contacts`: nullable, FK to `bcms_contacts.id`,
  `nullOnDelete`, plus `unique(['organization_id', 'ad_object_guid'])`.
- Every table carries `organization_id` with a constrained FK, and every model
  uses `BelongsToOrganization` (ADR 0006).
- Then: `php artisan bcms:verify-schema --write`, committed **with** the
  migration. A manifest regenerated in a later commit is a diff nobody reviews.

---

## 3. Enums, value objects, the interface

```
app/Enums/Bcms/IdentityProvider.php     entra            (one case; ADR 0018 §2.2 says why)
app/Enums/Bcms/SyncTrigger.php          scheduled_full | scheduled_delta | manual
app/Enums/Bcms/SyncRunStatus.php        running | success | partial | failed
app/Enums/Bcms/SyncChangeKind.php       joiner | leaver | mover | contact_change
app/Enums/Bcms/SyncChangeDecision.php   pending | approved | rejected | auto_applied | superseded
app/Enums/Bcms/AutoApplyPolicy.php      none | safe_only          (there is no `all`)

app/Support/Bcms/DirectoryAttributeMap.php   Blueprint §8.2 defaults + validation of a saved map
app/Support/Bcms/DirectoryUser.php           value object: objectId, upn, names, mail, phones,
                                             jobTitle, department, officeLocation, employeeId,
                                             accountEnabled, managerObjectId
```

**Cast every enum column on the model.** A string column read as a string in one
place and an enum in another is a shipped BCMS defect family
(`verification_status = 'failed'`, phase-7 notes §7): once cast, a value the enum
never declared throws `ValueError` on read.

```php
// app/Contracts/Bcms/DirectoryClient.php
interface DirectoryClient
{
    /** @return iterable<int, DirectoryUser> — pages internally, yields users */
    public function users(IdentityConnector $connector, ?string $filter = null): iterable;

    /** @return array{users: iterable<int, DirectoryUser>, delta_link: ?string} */
    public function delta(IdentityConnector $connector, ?string $deltaLink = null): array;

    public function managerObjectId(IdentityConnector $connector, string $objectId): ?string;

    /** @return array{ok: bool, scopes: list<string>, sample_count: int, error_class: ?string, error_code: ?string} */
    public function testConnection(IdentityConnector $connector): array;
}
```

There is no write method and there will not be one. `testConnection()` returns
the scopes the token actually came back with so an over-privileged app
registration is visible on the screen (ADR 0018 §3.2).

`EntraGraphClient` implements it over the **`Http` facade** — `Http::timeout()`,
explicit `$select`, `$top=999`, follow `@odata.nextLink`, honour `Retry-After` on
429, and a **404 from `/users/{id}/manager` means "no manager"**, not a failure.
`FakeDirectoryClient` implements it from a fixture array and is what every test
and the seeder use. Bind in `AppServiceProvider` — **do not create a
`BcmsServiceProvider`** (ADR 0007 deviation 2).

**Never log or store a provider message.** `error_class` + `error_code` (Graph's
bounded `error.code`, or the HTTP status), never `error.message`, never
`$e->getMessage()` — a Guzzle `ConnectException` message carries the full request
URI, which for a credentialed call is a secret. Phase 7's gate found this three
times in adapters and twice more in listeners; `HttpChannel::classifyProviderError()`
is the shape to copy.

---

## 4. Models and services

```
app/Models/Bcms/IdentityConnector.php    HasBcmsUuid, BelongsToOrganization, BcmsAuditable
app/Models/Bcms/IdentitySyncRun.php      HasBcmsUuid, BelongsToOrganization, BcmsAuditable
app/Models/Bcms/IdentitySyncChange.php   BelongsToOrganization, BcmsAuditable  (no uuid — nested child)

app/Services/Bcms/Identity/EntraGraphClient.php
app/Services/Bcms/Identity/FakeDirectoryClient.php
app/Services/Bcms/Identity/DirectorySyncService.php    orchestrates a run, writes staging rows only
app/Services/Bcms/Identity/ChangeDetector.php          directory vs roster → joiner/leaver/mover/contact_change
app/Services/Bcms/Identity/ImpactAssessor.php          call-tree + saved-audience impact → impact_json, requires_ack
app/Services/Bcms/Identity/ChangeApplier.php           the ONLY writer to bcms_contacts
app/Services/Bcms/Identity/ConnectorHealth.php         derived from the run table — no stored status column
```

- `IdentityConnector::casts()`: `'client_secret' => 'encrypted'`, `attribute_map`
  array, the four enums, `credential_expires_on` date, `is_active` bool.
  `$hidden = ['client_secret']`. It is never sent to a screen, not even redacted.
- `ChangeApplier` holds the write allowlist and the never-write list of ADR 0018
  §3.4 as **constants**, and a test asserts the constants, not the behaviour
  alone. It derives `manager_user_id` from `manager_contact_id`.
- `ImpactAssessor` computes downstream-blocked the way `BrokenBranchAnalyser`
  does — people actually left unreached, never the descendant count (phase-6
  notes §3). Reuse it; do not write a second counter.
- Anything that walks `manager_contact_id` walks it with a **visited set and a
  depth bound**. One cyclic parent edge has already cost this product a 512 MB
  OOM, and `TreeProposalService::arrange()` is the pattern.
- `TreeProposalService` and `EscalationService::managerContactFor()` read
  `manager_contact_id` first, `manager_user_id` as the fallback. Two small,
  surgical edits; do not restructure either file.

---

## 5. HTTP, routes, form requests

`app/Http/Controllers/Bcms/IdentityController.php` (connector: show, update, test,
sync-now) and `IdentitySyncController.php` (runs index/show, per-change decide,
bulk decide). Presenter: `app/Presenters/Bcms/IdentityPresenter.php` — controllers
do not compute (standard §1).

Inside the existing `Route::middleware('feature:bcms')->prefix('bcms')->name('bcms.')`
group, next to `settings`:

| Verb | URI | Name | Permission |
|---|---|---|---|
| GET | `settings/identity` | `bcms.settings.identity` | `bcms.identity.manage` |
| PUT | `settings/identity` | `bcms.settings.identity.update` | `bcms.identity.manage` |
| POST | `settings/identity/test` | `bcms.settings.identity.test` | `bcms.identity.manage` |
| POST | `settings/identity/sync` | `bcms.settings.identity.sync` | `bcms.identity.manage` |
| GET | `identity/runs` | `bcms.identity.runs.index` | `bcms.identity.review` |
| GET | `identity/runs/{run}` | `bcms.identity.runs.show` | `bcms.identity.review` |
| POST | `identity/runs/{run}/changes/{change}/decide` | `bcms.identity.changes.decide` | `bcms.identity.review` |
| POST | `identity/runs/{run}/changes/decide` | `bcms.identity.changes.bulk-decide` | `bcms.identity.review` |

- Register **before** the wildcard section route, exactly as `settings` is, or
  `identity` binds as a section key and 404s.
- `{run}` binds by **uuid** (`HasBcmsUuid`); `{change}` is a numeric nested child
  and the two nested routes carry **`->scopeBindings()`** (ADR 0017 §5). Any
  `tryRoute('bcms.identity…', x.id)` in a screen must pass `x.uuid` for the run —
  `BcmsRouteKeyTest` exists because thirteen BCMS buttons got this wrong and
  nothing failed loudly.
- **No `ModuleSections` entry and no new nav item.** These screens are reached
  from BCMS settings, which is what "an integration part on the settings for the
  admin" means.
- Form Requests, never inline `validate([...])` (standard §4). Bulk decide
  validates its id list with a tenant-bound
  `Rule::exists('bcms_identity_sync_changes', 'id')->where('organization_id', …)`
  further scoped to the bound run. Never `exists:table,id`.
- `client_secret` is `nullable` on update and an empty value **leaves the stored
  secret alone** — a blank field must not wipe a working credential.

---

## 6. Permissions

`app/Authorization/RiskPermissionCatalog.php`: add
`'bcms.identity.review' => 'Review the directory sync change queue and approve or reject joiners, leavers and movers.'`
beside `bcms.identity.manage`, and add it to the `$riskManager` BCMS block (the
BC Coordinator). `bcms.identity.manage` stays with `chief-risk-officer` only.
Nothing else to do: the seeder reads the catalogue through `SeedsPermissions`.
`PermissionCatalogCoversRoutesTest`, `RouteAuthorizationTest` and
`SuperAdminReachesEveryScreenTest` must stay green.

---

## 7. Queues, schedule, watchdog (reliability-engineer)

- Both jobs on the existing **`bcms-sync`** queue via `config('bcms.queues.sync')`.
  One Horizon supervisor, `tries = 1`, 3600s timeout — a half-run sync retried
  from the top writes the same contacts twice. **No fifth queue.**
- `app/Console/Commands/SyncBcmsDirectory.php` (`bcms:sync-directory
  {--delta}`), per-tenant loop, skips a tenant with no active connector, exits
  early when `features.bcms` is off.
- `routes/console.php`, beside the eight BCMS entries:
  `Schedule::command('bcms:sync-directory')->dailyAt('02:30')->withoutOverlapping();`
  and `Schedule::command('bcms:sync-directory --delta')->everyFifteenMinutes()->withoutOverlapping();`
- `BcmsWatchdog`: two checks — a connector whose last successful run is older
  than twice its schedule, and `credential_expires_on` inside 30 days. A secret
  that lapses silently freezes the roster.
- Write-ahead: the run row exists with `status = running` **before** the first
  Graph call (standing rule 8). A crashed worker leaves a visibly unfinished run,
  not a silent gap.

---

## 8. Screens (ui-designer specs first, then frontend-engineer)

`docs/bcms/screens/identity-connector.md` and
`docs/bcms/screens/identity-change-review.md` — `docs/<module>/screens/` is where
this repository keeps screen specs (`docs/tprm/screens/*`); the prompt's
`docs/design/screens/` does not exist here (ADR 0007). Then
`resources/js/Pages/Bcms/Settings/Identity.jsx` and
`resources/js/Pages/Bcms/Identity/Review.jsx`.

Content per ADR 0018 §5. Specify empty / loading / error / no-connector /
never-synced / secret-expiring states. 360px, WCAG 2.1 AA, usable at 100 kbps.
`@thirdline/ui` + Tailwind, Chart.js if anything is charted — **no Ant Design**
(ADR 0007 deviation 5). Every figure computed or absent: no `?? 87%`
(`NoFabricatedNumbersTest`). Rows needing acknowledgement are excluded from bulk
approve unless explicitly selected. If the review screen ever grows two submit
buttons, the intent goes in a `useRef` and through `transform()`, never
`setData` (standard §9).

---

## 9. Tests

`tests/Feature/Bcms/Phase2cIdentitySyncTest.php` (the nine criteria of ADR 0018
§6), `Phase2cScreensTest.php` (props boundary, permissions, 403/404 shape),
`Phase2cReadOnlyGuardTest.php` (§3.2's grep-style guard: no
`Http::post|put|patch|delete` to a Graph host outside the allowlisted token URL).

- `Http::preventStrayRequests()` in every identity test. **No test reaches the
  network.**
- Seeder: `database/seeders/Bcms/IdentityDemoSeeder.php` — a `FakeDirectoryClient`
  fixture of ~200 users over the 12 Kano Heritage departments, 5-level manager
  chain, 12 leavers, 8 movers, 30% missing mobiles, one deliberate management
  loop, every number in the reserved `+2348000` range. Run a real sync at seed
  time through the fake client, as `CallTreeDemoSeeder` runs a real cascade —
  nothing fixtured, so a wrong number on the screen means wrong code.
- Do **not** run the full suite. `php artisan test --filter=Phase2c…` and the
  named guards only.
- **Never force a `QueryException` with a schema change inside a
  `RefreshDatabase` test.** DDL implicitly commits on MariaDB 10.4 and the
  rollback does not undo it; that corrupted `risk_test_bcms7` once already
  (phase-7 notes §8). Use a constraint violation on DML instead.
- Before writing any MariaDB-compatibility fix, diff against
  `migration/phase-7-shared-packages` — it already carries a fix for every defect
  the MariaDB switch exposes.

---

## 10. Documents to update in the same PR

- `docs/compliance/ndpa-register.md` — compliance-analyst: lawful basis for
  reading directory attributes, purpose limitation, retention for
  `before_json`/`after_json`, residency, DSAR position. **Before gate 1.**
- `docs/bcms/phase-2c-notes.md` — written at the end, in the shape of the other
  phase notes: what landed, the decisions, the deviations, the defects found, the
  gaps handed forward.
- `database/schema/bcms-manifest.php` — regenerated with the migration.
- `docs/adr/0017-*` pinned map entries when Phase 7.5 lands, whichever way round
  they arrive (ADR 0018 §8).

## 11. Definition of done

Orchestration §7 in full, plus: `bcms:verify-schema` clean against the
regenerated manifest; the nine criteria of ADR 0018 §6 each proved by a test;
gate 1 then gate 2 passed; `## HANDOFF` block written by every agent.

## 12. The three things most likely to go wrong

1. **A json column holding ciphertext.** See §2. It has already shipped once in
   this product and broke a feature on every real database for months.
2. **A screen addressing a uuid-routed model by numeric id.** Silent 404, invisible
   to feature tests. `BcmsRouteKeyTest` is the net; pass `uuid`.
3. **A provider's error text reaching a stored column or the log.** `error_class`
   and `error_code` only. Three rounds of a Phase 7 gate went on this.
