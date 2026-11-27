# ADR 0028 — ERM Risk Library, Process Groups, and Process Module

**Status:** Accepted · **Date:** 2026-11-27 · **Phase:** ERM Risk Library & Process Module (P1–P4) · **Author:** architect
**Supersedes:** nothing · **Consumers:** backend-engineer · frontend-engineer · qa-engineer · code-reviewer · compliance-analyst · RCSA v2 track · BCMS track (regression boundary)

---

## Context

Today, Atheris ERM stores every risk directly in `risks` as a contextualised record (scores, owner, department/entity, process, controls, treatments, review dates). When the same risk — such as *Payment Settlement Failure* or *Unauthorised System Access* — applies to five branches or three processes, users must re-enter it five times with drifting titles, descriptions, causes, and categories. There is no master catalogue of risks, no versioned governance over risk definitions, and no way to answer an examiner's portfolio question: *"Which entities and processes have assessed this risk, which are missing it, and which are overdue?"*

Similarly, `business_processes` already exists as a flat or two-level (`parent_id`) table used by `risks.process_id`, RCSA v2 (`rcsa_register_risks.process_id` / `sub_process_id`), and BCMS (`bcms_processes.business_process_id` overlay per ADR 0001), but has no Process Groups, no dedicated ERM Process Module UI, and no link to `entities` (Entity Scoping).

### Codebase Verification Against Current State

Every assumption in the programme brief was verified directly against the live repository:

| Area | Verified Reality in Codebase |
|---|---|
| `risks` & `App\Models\Risk` | `business_unit_id` is **already nullable** in DB (`2026_02_22_200006`), as are `entity_id`, `process_id`, and `parent_risk_id`. Carries `risk_level`, `hierarchy_path`, `roll_up_weight`, `hierarchy_depth` (`2026_03_26_000001`). Uses `BelongsToOrganization`, `HasObjectIdentity`, `ScopedToGraph`, `SoftDeletes`. Has no `library_item_id`, `library_version`, `basel_l1_event_type`, `basel_l2_event_type`, or `consequences`. |
| `StoreRiskRequest` / `UpdateRiskRequest` | Both require `'business_unit_id' => ['required', Rule::exists(...)]` and do **not** accept `entity_id` yet. |
| `business_processes` & `App\Models\BusinessProcess` | Has `organization_id`, `business_unit_id` (nullable), `parent_id` (nullable self-FK added by RCSA v2 `2026_09_06_100003`), `code`, `name`, `description`, `owner_id`, `criticality`, `is_active`. Uses `BelongsToOrganization`, `HasObjectIdentity`, `SoftDeletes`. `scopeTopLevel()` filters `whereNull('parent_id')`. Has no `process_group_id`, no `entity_id`, and no unique index on `(organization_id, code)`. |
| `bcms_processes` & `App\Models\Bcms\Process` | Class is `App\Models\Bcms\Process` (aliased `'bcms_process'` in `MorphTypes`). Carries nullable `business_process_id` FK to `business_processes`. Per ADR 0001 Decision 4, it is a BCM overlay (`criticality_tier`, `is_critical_service`, BIA/RTO/RPO) and must never be mutated by ERM. |
| RCSA Universe (`rcsa_register_risks`) | `App\Models\Rcsa\RcsaRegisterRisk` has `business_unit_id` (not null), `process_id`, `sub_process_id`, `risk_category` (`varchar(64)` free text), `row_hash`. Relies on `business_processes.parent_id IS NULL` for Level-2 Processes and `parent_id IS NOT NULL` for Level-3 Sub-Processes. Has no `library_item_id` or `library_version`. |
| Entity Scoping (`ScopingController`, `Entity`, `Show.jsx`) | `Pages/Scoping/Show.jsx` has 5 tabs: `overview`, `risks`, `kris`, `issues`, `sub_entities`. `entities` has **no FK** to `business_units` and **no relationship** to `business_processes`. |
| `PROCESS` entity type | `ScopingSeeder` seeds 9 `entity_types` including `'PROCESS'` (level 3), though `seedEntities()` creates zero `PROCESS` entities. `ObjectTypeRegistry::legacyEntityTypeMap()` maps `'PROCESS' => 'Process'`. |
| Basel II/III L1/L2 taxonomy | `risk_categories.basel_category` holds Pillar 1 umbrellas only; `risk_taxonomies` is unseeded (`SeedSmOpEventTypes` references missing `App\Services\Sm\OpEventTypeCatalogue`); `LossEvent::BASEL_EVENT_TYPES` holds the 7 L1 codes as a PHP array. |
| Navigation & Routes | `NavPresenter::sections()` defines `scoping` (`/risk/scoping`) and `risk_register` (`/risk/register` with `risk.register.index` and `risk.register.create`). `NavPresenter::for()` restricts `risk-champion` / `risk-champions` (`$isChampionOnly`) to `['kri_monitoring', 'workflows', 'rcsa', 'loss_events']`. |
| Permissions & Roles | `App\Authorization\RiskPermissionCatalog` is the single source of truth; `permissionsFor()` has an explicit override for `risk-champion` / `risk-champions`. Grant migrations use `SeedsPermissions::grantFromCatalog()` (`2026_09_06_100006`). |
| Object Identity & Morph Map | `Relation::enforceMorphMap()` is active via `App\Support\MorphTypes::map()`. `RiskAuditTrail::ENTITY_TYPE_MAX = 50`, `ACTION_TYPE_MAX = 64`. `risk_audit_trail` is append-only with SHA-256 hash chain and DB triggers (`2026_08_09_100004`). |

### Disagreements Between the Prompt and `docs/DEVELOPMENT_STANDARD.md` / Repository

Where the prompt and the repository/standard diverge, **`docs/DEVELOPMENT_STANDARD.md` and the live repository win**:

1. **"Belongs to no department or entity" vs Standard §7 (Tenancy):** Risk Library items are entity- and department-agnostic *within a tenant*, but **must** carry `organization_id` via `ThirdLine\Platform\Tenancy\BelongsToOrganization` (covered by `TenancyIsolationTest`).
2. **"Filtered by Basel II/III event type from existing `risk_categories` / `risk_taxonomies`" vs live schema:** Neither table holds Basel II/III Level 1/2 operational risk event types (compliance-analyst Gap 1). Resolved via canonical PHP Enums (`BaselEventTypeL1`, `BaselEventTypeL2`) and dedicated nullable columns.
3. **"Process groups over existing `business_processes`" vs RCSA v2 invariant:** Storing Process Groups as rows in `business_processes` would violate `BusinessProcess::scopeTopLevel()` (`parent_id IS NULL`) and break `rcsa_register_risks.process_id` / `sub_process_id` (compliance-analyst Gap 3). Resolved with a dedicated `process_groups` table.
4. **"Rename Risk Register to Risk Library inside the Risk Register module" vs route stability:** All existing `risk.register.*` route names are preserved unchanged; Risk Library receives its own `risk.library.*` routes and sub-menu entry.
5. **"`BcmsProcess`" vs actual class name:** The model is `App\Models\Bcms\Process`.
6. **"`business_unit_id` nullable on `risks`" vs live schema:** Already nullable in the database; only `StoreRiskRequest` and `UpdateRiskRequest` need relaxation when `entity_id` is supplied.
7. **Compliance gaps incorporated:** Immutable `risk_library_item_versions` snapshots (Gap 4), `risks.consequences` JSON column (Gap 5), `rcsa_register_risks.library_item_id` (Gap 6), and one-click CSV/PDF examiner exports (Gap 7) are frozen in this ADR.

---

## Decision 1 — Naming, Navigation, and Route Stability *(Flagged for User Confirmation)*

> **User confirmation required before P1 code begins:** Confirm the sidebar labels, item order, and placement below.

1. **Sidebar section `risk_register` stays labelled `Risk Register`** (`icon: 'assessment'`, `prefixes: ['/risk/register', '/risk/library', '/risk/coverage']`), containing:
   - `Risk Register` → `risk.register.index` (`permission: risk.view`) — kept as `items[0]` so collapsed-sidebar icon-rail clicks (`AuthenticatedLayout.jsx`) continue landing on the register.
   - `Risk Library` → `risk.library.index` (`permission: risk_library.view`) — landed in **P1**.
   - `Create New Risk` → `risk.register.create` (`permission: risk.create`) — unchanged.
   - `Coverage Matrix` → `risk.coverage.index` (`permission: risk_library.view`) — landed in **P4**.
2. **New sidebar section `processes` labelled `Processes`** (`icon: 'schema'`, `prefixes: ['/risk/processes', '/risk/process-groups']`), placed **immediately after `Scoping`** in Phase 1 (Context & Governance), containing (landed in **P2**):
   - `Process Dashboard` → `risk.processes.dashboard` (`permission: process.view`)
   - `Process Catalogue` → `risk.processes.index` (`permission: process.view`)
   - `Process Groups` → `risk.process-groups.index` (`permission: process.view`)
3. **Route names preserved 100%:** Every existing `risk.register.*` route name and URL (`/risk/register/...`) remains untouched. New route groups:
   - `risk.library.*` (`/risk/library/...`) — P1
   - `risk.processes.*` (`/risk/processes/...`) — P2
   - `risk.process-groups.*` (`/risk/process-groups/...`) — P2
   - `risk.coverage.*` (`/risk/coverage/...`) — P4

**Alternative rejected:** Splitting `Risk Library` and `Risk Register` into two top-level sidebar sections, or renaming `risk.register.*` routes. The sidebar already has 25 sections, and renaming routes would break existing bookmarks, deep links, and tests.

---

## Decision 2 — Exact Frozen Schema (MariaDB 10.4 ∩ MySQL 8.0.46, ADR 0022 Compliant)

All DDL obeys the cross-engine intersection: no raw JSON SQL functions, no CTEs, no window functions, no subquery-in-`UPDATE` on the same table, explicit index names ≤ 64 chars, and **ADR 0022 timestamps** (`timestamps()` for `created_at`/`updated_at` only; all application-written event times are `dateTime()`, never bare `timestamp()` or `useCurrentOnUpdate()`).

### 2.1 Canonical Basel II/III Event Type Enums (PHP, no table)
- `App\Enums\BaselEventTypeL1` (backed `string`, 7 BCBS Annex 9 / OPE25 Level 1 codes matching `LossEvent::BASEL_EVENT_TYPES`):
  `internal_fraud`, `external_fraud`, `employment_practices`, `clients_products`, `damage_physical_assets`, `business_disruption`, `execution_delivery`.
- `App\Enums\BaselEventTypeL2` (backed `string`, 20 canonical Level 2 codes, each implementing `l1(): BaselEventTypeL1`).
- Validation rule: both columns are nullable (non-operational risks such as Credit/Market/Liquidity carry `NULL`); when `basel_l2_event_type` is set, `basel_l1_event_type` is required and `BaselEventTypeL2::from($l2)->l1()->value === $l1`.

### 2.2 New Table: `risk_library_items` (P1)

| Column | Type | Null / Default | Constraint / Notes |
|---|---|---|---|
| `id` | `bigint unsigned` | NOT NULL, AI | PK |
| `uuid` | `char(36)` | NOT NULL | UNIQUE |
| `organization_id` | `bigint unsigned` | NOT NULL | FK → `organizations.id` `ON DELETE CASCADE` |
| `code` | `varchar(32)` | NOT NULL | e.g. `RL-0001`; unique per org |
| `title` | `varchar(255)` | NOT NULL | Master risk statement title |
| `description` | `text` | NOT NULL | Master risk description |
| `category_id` | `bigint unsigned` | NOT NULL | FK → `risk_categories.id` `ON DELETE RESTRICT` |
| `risk_source` | `varchar(40)` | NULL | e.g. `internal`, `external`, `people`, `process`, `systems` |
| `basel_l1_event_type` | `varchar(40)` | NULL | Validated against `BaselEventTypeL1` |
| `basel_l2_event_type` | `varchar(64)` | NULL | Validated against `BaselEventTypeL2` (child of L1) |
| `typical_causes` | `json` | NULL | `list<{cause: string, cause_category_id: ?int}>` |
| `typical_consequences` | `json` | NULL | `list<{consequence: string, impact_domain: ?string}>` |
| `suggested_controls` | `json` | NULL | `list<{title: string, control_id: ?int, control_type: ?string}>` |
| `suggested_kris` | `json` | NULL | `list<{name: string, kri_id: ?int, metric_unit: ?string}>` |
| `current_version` | `int unsigned` | NOT NULL, default `1` | Incremented on every substantive edit |
| `status` | `varchar(20)` | NOT NULL, default `'active'` | `'active'` \| `'retired'` |
| `retired_at` | `datetime` | NULL | ADR 0022 (`dateTime`) |
| `retired_by` | `bigint unsigned` | NULL | FK → `users.id` `ON DELETE SET NULL` |
| `retirement_reason` | `text` | NULL | Recorded on retirement |
| `created_by` | `bigint unsigned` | NULL | FK → `users.id` `ON DELETE SET NULL` |
| `updated_by` | `bigint unsigned` | NULL | FK → `users.id` `ON DELETE SET NULL` |
| `created_at` / `updated_at` | `timestamp` | NULL | Standard Laravel `timestamps()` |

**No `softDeletes()`** — library items are retired, never deleted (Decision 6). **Zero score columns** (ISO 31000 §6.4.2).

**Indexes:**
- `UNIQUE (organization_id, code)` — `rli_org_code_unique`
- `INDEX (organization_id, status)` — `rli_org_status_idx`
- `INDEX (organization_id, category_id)` — `rli_org_cat_idx`
- `INDEX (organization_id, basel_l1_event_type, basel_l2_event_type)` — `rli_org_basel_idx`

### 2.3 New Table: `risk_library_item_versions` (P1 — Immutable Snapshots)

| Column | Type | Null / Default | Constraint / Notes |
|---|---|---|---|
| `id` | `bigint unsigned` | NOT NULL, AI | PK |
| `organization_id` | `bigint unsigned` | NOT NULL | FK → `organizations.id` `ON DELETE CASCADE` |
| `library_item_id` | `bigint unsigned` | NOT NULL | FK → `risk_library_items.id` `ON DELETE RESTRICT` |
| `version_number` | `int unsigned` | NOT NULL | `1, 2, 3, ...` |
| `title` | `varchar(255)` | NOT NULL | Snapshot of title at this version |
| `description` | `text` | NOT NULL | Snapshot of description |
| `category_id` | `bigint unsigned` | NOT NULL | FK → `risk_categories.id` `ON DELETE RESTRICT` |
| `risk_source` | `varchar(40)` | NULL | Snapshot |
| `basel_l1_event_type` | `varchar(40)` | NULL | Snapshot |
| `basel_l2_event_type` | `varchar(64)` | NULL | Snapshot |
| `typical_causes` | `json` | NULL | Snapshot |
| `typical_consequences` | `json` | NULL | Snapshot |
| `suggested_controls` | `json` | NULL | Snapshot |
| `suggested_kris` | `json` | NULL | Snapshot |
| `change_summary` | `text` | NULL | Required on `version_number > 1` |
| `created_by` | `bigint unsigned` | NULL | FK → `users.id` `ON DELETE SET NULL` |
| `created_at` | `datetime` | NOT NULL | ADR 0022 (`dateTime`, explicitly written as UTC `now()`); no `updated_at` |

**Indexes:**
- `UNIQUE (library_item_id, version_number)` — `rliv_item_version_unique`
- `INDEX (organization_id, library_item_id)` — `rliv_org_item_idx`

**Immutability:** `RiskLibraryItemVersion::booted()` throws `LogicException` on `updating` and `deleting`.

### 2.4 Alterations to `risks` (P1)

| Column | Type | Null / Default | Constraint / Notes |
|---|---|---|---|
| `library_item_id` | `bigint unsigned` | NULL | FK → `risk_library_items.id` `ON DELETE RESTRICT` (after `category_id`) |
| `library_version` | `int unsigned` | NULL | Version accepted by this instance (after `library_item_id`) |
| `library_synced_at` | `datetime` | NULL | ADR 0022 (`dateTime`, after `library_version`) |
| `library_update_dismissed_version` | `int unsigned` | NULL | Suppresses review banner for this exact version only (after `library_synced_at`) |
| `basel_l1_event_type` | `varchar(40)` | NULL | After `risk_source` |
| `basel_l2_event_type` | `varchar(64)` | NULL | After `basel_l1_event_type` |
| `consequences` | `json` | NULL | Structured ISO 31000 §6.4.2 consequences copied from library (after `metadata`) |

**Indexes:**
- `INDEX (organization_id, library_item_id)` — `risks_org_library_item_idx`
- `INDEX (organization_id, entity_id, process_id)` — `risks_org_entity_process_idx`
- `INDEX (organization_id, basel_l1_event_type)` — `risks_org_basel_l1_idx`

### 2.5 New Table: `process_groups` (P2)

| Column | Type | Null / Default | Constraint / Notes |
|---|---|---|---|
| `id` | `bigint unsigned` | NOT NULL, AI | PK |
| `uuid` | `char(36)` | NOT NULL | UNIQUE |
| `organization_id` | `bigint unsigned` | NOT NULL | FK → `organizations.id` `ON DELETE CASCADE` |
| `business_unit_id` | `bigint unsigned` | NULL | FK → `business_units.id` `ON DELETE SET NULL` (Decision 5) |
| `code` | `varchar(32)` | NOT NULL | e.g. `PG-001`; unique per org |
| `name` | `varchar(200)` | NOT NULL | Process group name |
| `description` | `text` | NULL | Scope description |
| `apqc_code` | `varchar(32)` | NULL | Optional APQC PCF reference (e.g. `9.2`) |
| `owner_id` | `bigint unsigned` | NULL | FK → `users.id` `ON DELETE SET NULL` |
| `sort_order` | `smallint unsigned` | NOT NULL, default `0` | Display order |
| `status` | `varchar(20)` | NOT NULL, default `'active'` | `'active'` \| `'inactive'` |
| `created_by` | `bigint unsigned` | NULL | FK → `users.id` `ON DELETE SET NULL` |
| `updated_by` | `bigint unsigned` | NULL | FK → `users.id` `ON DELETE SET NULL` |
| `created_at` / `updated_at` | `timestamp` | NULL | Standard `timestamps()` |
| `deleted_at` | `timestamp` | NULL | `softDeletes()` |

**Indexes:**
- `UNIQUE (organization_id, code)` — `pg_org_code_unique`
- `INDEX (organization_id, business_unit_id)` — `pg_org_bu_idx`
- `INDEX (organization_id, status)` — `pg_org_status_idx`

### 2.6 Alterations to `business_processes` & New Pivot `entity_business_process` (P2)

On `business_processes`:
- `process_group_id` (`bigint unsigned`, NULL, after `business_unit_id`) — FK → `process_groups.id` `ON DELETE SET NULL`
- `apqc_code` (`varchar(32)`, NULL, after `code`)
- `INDEX (organization_id, process_group_id)` — `bp_org_group_idx`
- `INDEX (organization_id, code)` — `bp_org_code_idx` *(non-unique in DDL to avoid failing on any pre-existing tenant duplicates; uniqueness enforced in Form Requests via tenant-scoped `Rule::unique`)*

New pivot table `entity_business_process` (Decision 3):

| Column | Type | Null / Default | Constraint / Notes |
|---|---|---|---|
| `id` | `bigint unsigned` | NOT NULL, AI | PK |
| `organization_id` | `bigint unsigned` | NOT NULL | FK → `organizations.id` `ON DELETE CASCADE` |
| `entity_id` | `bigint unsigned` | NOT NULL | FK → `entities.id` `ON DELETE CASCADE` |
| `business_process_id` | `bigint unsigned` | NOT NULL | FK → `business_processes.id` `ON DELETE CASCADE` |
| `is_primary` | `boolean` | NOT NULL, default `false` | Marks originating entity when created from Scoping |
| `attached_by` | `bigint unsigned` | NULL | FK → `users.id` `ON DELETE SET NULL` |
| `created_at` | `datetime` | NOT NULL | ADR 0022 (`dateTime`, explicitly written as UTC `now()`) |

**Indexes:**
- `UNIQUE (entity_id, business_process_id)` — `ebp_entity_process_unique`
- `INDEX (organization_id, entity_id)` — `ebp_org_entity_idx`
- `INDEX (organization_id, business_process_id)` — `ebp_org_process_idx`

### 2.7 Alterations to `rcsa_register_risks` (P4)

| Column | Type | Null / Default | Constraint / Notes |
|---|---|---|---|
| `library_item_id` | `bigint unsigned` | NULL | FK → `risk_library_items.id` `ON DELETE RESTRICT` (after `sub_process_id`) |
| `library_version` | `int unsigned` | NULL | After `library_item_id` |

**Index:** `INDEX (organization_id, library_item_id)` — `rcsa_rr_org_library_item_idx`.

---

## Decision 3 — Entity ↔ Process Link & `PROCESS` Entity Type *(Flagged for User Confirmation)*

1. **Entity ↔ Process link is M:N via `entity_business_process`.**
   - A single canonical `business_processes` record can be attached to multiple entities (e.g., *Account Opening* executed across Retail Banking Division and multiple branches).
   - Creating or attaching a process from `Pages/Scoping/Show.jsx` (the entity's **Processes** tab) writes to `business_processes` + `entity_business_process`. The exact same `business_processes` record immediately appears in the Process Module, in RCSA Universe (`parent_id IS NULL` for Process, `parent_id IS NOT NULL` for Sub-Process), and remains linkable from `bcms_processes.business_process_id`.
   - `business_processes.business_unit_id` remains untouched so RCSA v2 and `BusinessProcessSeeder` continue working without regression.
   - **Alternatives rejected:**
     - *Single `business_processes.entity_id` FK (1:N):* Forces banks to duplicate shared processes per branch/subsidiary.
     - *Coupling `entities` to `business_units`:* The two trees have no 1:1 mapping; unifying them is a separate programme outside this ADR.

2. **`PROCESS` Entity Type Deprecation & Non-Destructive Migration:**
   - **Deprecate for new creation:** In `ScopingSeeder`, set `PROCESS` entity type `is_active = false` for fresh installs; hide `PROCESS` from `Scoping/Create.jsx` and reject new `PROCESS`-type entity creation in `StoreEntityRequest` (while allowing existing `PROCESS`-type entities to remain readable and editable). Keep `'PROCESS' => 'Process'` in `ObjectTypeRegistry::legacyEntityTypeMap()` so existing graph nodes stay valid.
   - **Non-destructive migration command (`php artisan erm:migrate-process-entities [--dry-run] [--apply]`):**
     - Defaults to `--dry-run`, listing any existing `PROCESS`-type `entities` rows, their parent entity, and their linked risks/controls/KRIs.
     - Under `--apply` (explicit admin confirmation only — **never** run automatically in a migration): creates or matches a `business_processes` row, attaches it to the parent entity via `entity_business_process`, updates `risks.process_id` (and sets `risks.entity_id` to the parent entity), and marks the legacy `PROCESS` entity `status = 'inactive'` with `metadata.migrated_to_business_process_id`.
     - **Never deletes or hard-merges any `entities` row.**

---

## Decision 4 — Instance Copy vs Live Read & `risks.business_unit_id` Nullability

### 4.1 Field-by-Field Ownership Contract

| Category | Fields | Behaviour |
|---|---|---|
| **Copied at instantiation** (stored on `risks` at `library_version`) | `title`, `description`, `category_id`, `risk_source`, `basel_l1_event_type`, `basel_l2_event_type`, `consequences` (from `typical_consequences`), `risk_causes` rows (seeded from `typical_causes`), `library_item_id`, `library_version`, `library_synced_at` | Copied once when the risk instance is created. Immune to subsequent edits on `risk_library_items` until the user explicitly accepts an update. |
| **Read live from `risk_library_items`** | `code` (display badge `RL-xxxx`), `current_version`, `status` (`active`/`retired`), `suggested_controls`, `suggested_kris` | Never duplicated onto `risks`. Drives: (a) **"Library updated (v{n} → v{m}) — review changes"** banner when `item.current_version > risk.library_version` AND `risk.library_update_dismissed_version !== item.current_version`, (b) **"Retired in Library"** badge when `item.status === 'retired'`, and (c) reference suggestions on `Register/Show.jsx`. |
| **Instance-only** (never overwritten by library) | `risk_code`, `entity_id`, `business_unit_id`, `process_id`, `parent_risk_id`, `risk_level`, `risk_owner_id`, `risk_steward_id`, `inherent_*`, `residual_*`, `target_rating`, `control_effectiveness_pct`, `financial_exposure_ngn`, `treatment_strategy`, `review_frequency`, `last_assessment_date`, `next_review_date`, `status`, `assessments`, `controls`, `kris`, `treatmentPlans` | Owned exclusively by the contextualised risk instance. |

### 4.2 Explicit Update Review Flow (No Silent Propagation)
- Rendered as a side-by-side diff comparing `risk_library_item_versions` at `risk.library_version` vs `item.current_version`.
- Two-submit-button form (`Accept Update` vs `Keep Current`) **must** use `useRef` + `transform()` (Standard §9).
- **Accept Update:** Updates ONLY the copied definition fields above, sets `library_version = item.current_version`, `library_synced_at = now()->utc()`, clears `library_update_dismissed_version = null`, and records `library_update_accepted` in `risk_audit_trail`. **Never** overwrites scores, owner, controls, treatments, or review dates.
- **Keep Current (Dismiss):** Sets `library_update_dismissed_version = item.current_version` and records `library_update_dismissed` in `risk_audit_trail`. Banner stays hidden for that version and reappears if the library item advances to `current_version + 1`.

### 4.3 `risks.business_unit_id` Nullability
- `risks.business_unit_id` is already nullable in the database (`2026_02_22_200006`).
- Update `StoreRiskRequest` and `UpdateRiskRequest`:
  - `'entity_id' => ['nullable', Rule::exists('entities', 'id')->where('organization_id', $orgId)]`
  - `'business_unit_id' => ['nullable', 'required_without:entity_id', Rule::exists('business_units', 'id')->where('organization_id', $orgId)]`
- Instantiating a risk onto an Entity no longer requires fabricating a `business_unit_id`, while standalone register creation still requires at least one organisational anchor (`entity_id` or `business_unit_id`).

---

## Decision 5 — Process Group Department Scoping

- **`process_groups.business_unit_id` is nullable.**
- When created from a department context, `business_unit_id` is populated; when `NULL`, the group represents an enterprise-wide / cross-departmental process group (e.g., *Customer Onboarding & KYC*, *Payment Clearing & Settlement*, *IT Service Management*) displayed under an "Enterprise / Cross-Departmental" bucket in the Process Catalogue and Dashboard.
- **Alternative rejected:** Requiring `business_unit_id NOT NULL`. Rejected because banking value chains routinely span multiple departments (Retail, Operations, Compliance, IT); forcing a single department would either misattribute shared groups or cause duplicate process groups across departments.

---

## Decision 6 — Retiring a Library Item

1. **Hard delete is permanently blocked:**
   - `RiskLibraryItemPolicy::delete()` always returns `false`.
   - No `DELETE` route exists for `risk_library_items`, and no `softDeletes()` column is added.
   - Foreign keys from `risks`, `rcsa_register_risks`, and `risk_library_item_versions` use `ON DELETE RESTRICT`.
2. **Retire (`POST /risk/library/{item}/retire`, `permission:risk_library.retire`):**
   - Permitted even when live instances exist.
   - Sets `status = 'retired'`, `retired_at = now()->utc()`, `retired_by = auth()->id()`, `retirement_reason = $reason`.
   - Writes `library_item_retired` to `risk_audit_trail`.
3. **Reactivate (`POST /risk/library/{item}/reactivate`, `permission:risk_library.retire`):**
   - Permitted on retired items. Sets `status = 'active'`, clears `retired_at` / `retired_by`, writes `library_item_reactivated` to `risk_audit_trail`.
4. **Effect on live instances & pickers:**
   - Existing `risks` and `rcsa_register_risks` instances stay intact, assessable, and reportable, displaying a **"Retired in Library"** badge.
   - Retired items are excluded by default from all "Add from Risk Library" and Bulk Apply pickers (filterable on the Risk Library index only).

**Alternative rejected:** Blocking retirement while live instances exist (prevents deprecating obsolete risk definitions without prematurely closing active assessments) or nullifying `library_item_id` on delete (destroys ISO 31000 §6.7 audit provenance).

---

## Decision 7 — Permissions, Roles, Policies, and Grant Migrations

### 7.1 `App\Authorization\RiskPermissionCatalog` Declarations (Standard §2)

Declared in two-segment `resource.verb` format, **added in the phase that ships their routes** so `PermissionCatalogCoversRoutesTest` stays green at every phase gate:

- **P1 — Module `'Risk Library'`:**
  - `risk_library.view` — `'See the master risk library, its version history and which entities carry each risk.'`
  - `risk_library.create` — `'Add a new master risk definition to the risk library.'`
  - `risk_library.update` — `'Edit a risk library definition, creating a new version for instances to review.'`
  - `risk_library.retire` — `'Retire or reactivate a risk library definition. Existing risk instances remain intact.'`
- **P2 — Module `'Processes'`:**
  - `process.view` — `'See the process catalogue, process groups and process dashboard.'`
  - `process.create` — `'Add a business process or sub-process to the process catalogue.'`
  - `process.update` — `'Edit a business process, its owner, criticality or entity attachments.'`
  - `process_group.manage` — `'Create, edit and deactivate process groups.'`

### 7.2 Role Grant Matrix

| Role | P1 (`risk_library.*`) | P2 (`process.*`, `process_group.*`) |
|---|---|---|
| `super-admin` | `*` | `*` |
| `chief-risk-officer` | `view`, `create`, `update`, `retire` | `process.view`, `create`, `update`, `process_group.manage` |
| `risk-manager` | `view`, `create`, `update`, `retire` | `process.view`, `create`, `update`, `process_group.manage` |
| `risk-analyst` | `view`, `create`, `update` *(no retire)* | `process.view`, `create`, `update` *(no `process_group.manage`)* |
| `risk-owner` | `view` *(read-only library; instantiates via `risk.create`)* | `process.view`, `create`, `update` |
| `compliance-officer` | `view` | `process.view` |
| `board-member` | `view` | `process.view` |
| `risk-champion` / `risk-champions` | — *(not granted: owner decision 2026-11-27 — champions' menu is limited to KRI Monitoring, RCSA, Loss Events and Workflows, and the library/process detail pages expose register data they cannot otherwise read)* | — *(not granted, same decision)* |

### 7.3 Grant Migrations & Policies
- **Grant migrations:** One additive grant migration in P1 and one in P2, following `2026_09_06_100006_grant_rcsa_universe_permissions_to_roles.php` via `SeedsPermissions::grantFromCatalog(new RiskPermissionCatalog, [...])` (additively — never destructive `syncPermissions()`; `risk-champion` / `risk-champions` are deliberately NOT granted, see the role table).
- **Bulk-apply in `processes` mode creates risks with no `entity_id`** (`RiskLibraryService::bulkApply`). Such a risk sits outside every branch-pinned user's `visibleTo()` — including its creator's — and no org-tree reach check applies in this mode, because a process is organisation-wide. Full-organisation roles see and manage them; a branch-pinned user should apply to entities instead. Revisit if process-scoped reach is ever needed.
- **Model-named Policies (Standard §3):**
  - `App\Policies\RiskLibraryItemPolicy` (P1) — `viewAny`, `view`, `create`, `update`, `retire`, `delete` (always `false`).
  - `App\Policies\ProcessGroupPolicy` (P2) — `viewAny`, `view`, `create`, `update`, `delete`.
  - `App\Policies\BusinessProcessPolicy` (P2) — `viewAny`, `view`, `create`, `update`, `delete`.

---

## Cross-Cutting Contracts

### Object Identity, Graph Registry & Morph Map

1. **`App\Support\MorphTypes::map()`** (all aliases ≤ 50 chars, `RiskAuditTrail::ENTITY_TYPE_MAX`):
   - `'risk_library_item' => \App\Models\RiskLibraryItem::class` (17 chars, P1)
   - `'risk_library_item_version' => \App\Models\RiskLibraryItemVersion::class` (25 chars, P1)
   - `'process_group' => \App\Models\ProcessGroup::class` (13 chars, P2)
   - `'business_process' => \App\Models\BusinessProcess::class` *(already registered)*
2. **`HasObjectIdentity`, `ObjectTypeRegistry`, `ObjectSourceMap`:**
   - `RiskLibraryItem` (P1): uses `HasObjectIdentity`; registered in `ObjectTypeRegistry::referenceTypes()` (`code: 'RiskLibraryItem'`, `prefix: 'RL'`) and `ObjectSourceMap::all()` (`table: 'risk_library_items'`, `code: 'code'`, `name: 'title'`, `lifecycle_state: 'status'`, `is_node: false`).
   - `ProcessGroup` (P2): uses `HasObjectIdentity`; registered in `ObjectTypeRegistry::orgNodeTypes()` (`code: 'ProcessGroup'`, `prefix: 'PG'`, `allowed_child_type_codes: ['Process']`) and `ObjectSourceMap::all()` (`table: 'process_groups'`, `code: 'code'`, `name: 'name'`, `business_unit: 'business_unit_id'`, `owner: 'owner_id'`, `lifecycle_state: 'status'`, `is_node: true`).
   - `RiskLibraryItemVersion`: **not** a graph object (immutable child snapshot only).

### Audit Trail (`risk_audit_trail`) Action Types (all ≤ 64 chars)

Written through `App\Services\AuditTrailService::record()`:

| Entity (`entity_type`) | `action_type` Values |
|---|---|
| `risk_library_item` | `library_item_created`, `library_item_updated`, `library_item_versioned`, `library_item_retired`, `library_item_reactivated` |
| `risk` | `risk_instantiated_from_library`, `risk_linked_to_library`, `risk_detached_from_library`, `library_update_accepted`, `library_update_dismissed` |
| `process_group` | `process_group_created`, `process_group_updated`, `process_group_deactivated` |
| `business_process` | `process_created`, `process_updated`, `process_attached_to_entity`, `process_detached_from_entity` |

### Seeding Command for Existing Risks (`php artisan erm:seed-risk-library`)

- Groups existing unlinked `risks` (`library_item_id IS NULL`) per organization by `(category_id, normalized_title)` where `normalized_title = Str::lower(trim(preg_replace('/\s+/', ' ', $title)))`.
- **Dry-run by default (`--dry-run`):** outputs candidate library items, matched risk counts, and near-duplicate warnings.
- **Explicit confirmation (`--confirm` or interactive prompt):** creates `risk_library_items` + `v1` snapshots and sets `library_item_id`, `library_version = 1`, `library_synced_at = now()->utc()` on matching `risks` rows, logging `risk_linked_to_library` in `risk_audit_trail`.
- **Never auto-merges, overwrites, or deletes any existing `risks` row.**

---

## Four-Phase Delivery Contract

| Phase | Scope & Deliverables | Gate Criteria |
|---|---|---|
| **P1 — Risk Library Foundation** | `BaselEventTypeL1`/`L2` enums; migrations (`risk_library_items`, `risk_library_item_versions`, `risks` additions); models + `MorphTypes` + `ObjectTypeRegistry` + `ObjectSourceMap`; `RiskLibraryItemPolicy`; `risk_library.*` permissions + grant migration; `RiskLibraryService`; `StoreRiskRequest`/`UpdateRiskRequest` (`business_unit_id` nullable when `entity_id` set); `erm:seed-risk-library` command; Risk Library Inertia screens (`Index`, `Create`, `Show`, `Edit`); review-update banner (`useRef` + `transform()`) & retired badge on `Register/Show.jsx`; `NavPresenter` update; CSV/PDF Risk Library export + extended `ExportController::riskRegister()`. | All existing `risk.register.*` tests green; `TenancyIsolationTest`, `RouteAuthorizationTest`, `NoSelfUpdatingTimestampColumnsTest`, `NoFabricatedNumbersTest` green; `audit:verify` passes. |
| **P2 — Process Groups & Process Module** | Migrations (`process_groups`, `business_processes` additions, `entity_business_process` pivot); `ProcessGroup` model + graph/morph wiring; `ProcessGroupPolicy` & `BusinessProcessPolicy`; `process.*` + `process_group.manage` permissions + grant migration; `ProcessService` & `ProcessDashboardPresenter`; Process Module Inertia screens (`Dashboard`, `Index`, `Show`, `Create/Edit`, `ProcessGroups/Index`); `NavPresenter` `Processes` section; Process Catalogue CSV/PDF export; `erm:migrate-process-entities` command. | Full RCSA v2 (`parent_id IS NULL` invariant) and BCMS (`bcms_processes.business_process_id` overlay untouched) regression green. |
| **P3 — Entity Scoping Integration & Bulk Apply** | `Pages/Scoping/Show.jsx` **Processes tab** (attach existing or create new process/sub-process onto entity) + **Risks tab "Add from Risk Library"** picker; **Bulk Apply from Risk Library** (1..N items × 1..N entities/processes with mandatory preview showing existing vs new instances, atomic creation, audit trail); deprecate `PROCESS` entity type on `Scoping/Create.jsx`. | Process created from Entity tab appears identically in Process Module & RCSA Universe; bulk apply never creates silent duplicates; `npm run build` clean. |
| **P4 — RCSA Linkage, Coverage Matrix & Gap Views** | Migration adding `library_item_id` + `library_version` to `rcsa_register_risks`; RCSA Universe "Pick from Risk Library" dual-entry; **Coverage Matrix** (`risk.coverage.index`: library items × entities/processes with Assessed / Missing / Overdue using `whereDate('next_review_date', '<', now()->toDateString())` per Standard §14, `null`/`—` for empty denominators per Standard §5); **Gap Views** (processes with no risks, unused library risks, entities with no processes); Portfolio Roll-Up guarding against `parent_risk_id` double-counting against `risk_appetite`; one-click Examiner CSV/PDF exports. | All 5 Examiner Walkthrough questions in `docs/compliance/erm-risk-library-process-clause-map.md` §4 pass in one click. |

---

## What This ADR Deliberately Does NOT Do

- **Does NOT silently propagate** library edits into existing risk instances or overwrite scores, owners, controls, treatments, or review dates.
- **Does NOT auto-merge or delete** existing `risks` rows or `PROCESS`-type `entities` rows.
- **Does NOT touch any `bcms_*` table** (`bcms_processes.is_critical_service` and `criticality_tier` remain owned exclusively by BCMS per ADR 0001).
- **Does NOT unify `entities` and `business_units`** into a single table.
- **Does NOT add a new ServiceProvider** (ERM uses the flat house layout; routes in `routes/web.php`, morphs in `MorphTypes`).
- **Does NOT replace CBN ORMS loss-event filings** (`loss_events` remains the regulatory return source; the Risk Library provides upstream Basel L1/L2 taxonomy alignment).
- **Does NOT auto-create `controls` or `key_risk_indicators`** when instantiating a library risk (suggested controls/KRIs are reference prompts only).
- **Does NOT provide hard deletion** of `risk_library_items`.

---

## Consequences

- Every risk instance in `risks` and `rcsa_register_risks` can trace back to a versioned, immutable `risk_library_item_versions` snapshot and a hash-chained `risk_audit_trail` entry, satisfying ISO 31000 §6.4.2, §6.4.3, §6.7, COSO ERM Principle 14, and Basel II/III OPE25.
- RCSA v2 and BCMS suffer zero breaking changes because `business_processes.parent_id = NULL` remains strictly Level-2 Processes and `bcms_processes` remains untouched.
- `TenancyIsolationTest`, `RouteAuthorizationTest`, `PermissionCatalogCoversRoutesTest`, `NoSelfUpdatingTimestampColumnsTest`, and `NoFabricatedNumbersTest` guard every phase automatically.
