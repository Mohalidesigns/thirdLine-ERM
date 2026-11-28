# ERM Risk Library & Process Module — Standards Clause Map & Compliance Review

**Owner:** compliance-analyst · **Scope:** Atheris ERM (`riskerm`) — Risk Library, Entity/Process Instantiation, Process Module, Scoping Processes Tab · **Status:** Pre-architecture review (G0)

This document verifies the compliance and standards claims for the proposed ERM Risk Library and Process Module changes against (a) the authoritative standards and Nigerian regulatory instruments, (b) the actual live schema, seeders, and services in `riskerm`, and (c) `docs/DEVELOPMENT_STANDARD.md`.

> **Evidence principle:** A feature is only marked **Met** when it stores a first-class, actor-stamped, tamper-evident record AND can export it in one click in a shape an examiner or auditor can inspect without manual assembly. Storing the right column without an export is **Partial**; claiming a taxonomy or linkage that does not exist in the codebase is a **Gap**.

---

## 1. Clause Map

| Standard | Clause / Requirement | Code | Feature / Artefact | Storage (Table / Columns) | Export / Examiner "Show Me" Sufficiency | Status |
|---|---|---|---|---|---|---|
| **ISO 31000:2018** | §6.4.2 Risk identification — identify risk sources, events, causes, consequences, and indicators without conflating generic definition with contextual assessment | `iso31000.6.4.2` | **Risk Library** (org-scoped, entity/department-agnostic master catalogue) storing title, description, category, risk source, typical causes, typical consequences, suggested controls, suggested KRIs — **zero scores** | Proposed: `risk_library_items` (new) + `risk_library_item_versions` (new). Existing: `risk_cause_categories`, `risk_causes` (`2026_08_18_120001`). **Gap:** `risks` has no structured `consequences` column/table. | **Gap today:** No Risk Library export exists; `ExportController::riskRegister()` (`app/Http/Controllers/Risk/ExportController.php`) omits `library_item_id`, `library_version`, `entity_id`, and `process_id`. Must add library CSV/PDF export and extend register export. | **Gap** (to be built) |
| **ISO 31000:2018** | §6.4.3–6.4.4 Risk analysis & evaluation — contextualised likelihood, impact, controls, ownership, treatment, and review cadence on the assessed instance | `iso31000.6.4.3` | **Risk Instance** created from library into an entity/process with scores, owner, steward, treatment strategy, and review dates | Existing: `risks` (`inherent_*`, `residual_*`, `risk_owner_id`, `treatment_strategy`, `next_review_date`, `entity_id`, `process_id`), `risk_assessments`, `risk_assessment_controls`. Proposed: add `library_item_id`, `library_version`, `library_synced_at` (or `library_version_accepted_at`) on `risks`. | Partial: `ExportController::riskRegister()` exports scores/owners/treatments, but must add library provenance (`library_code`, `library_version`, sync status) so an auditor can trace instance → master definition. | **Partial** |
| **ISO 31000:2018** | §6.7 Recording and reporting — traceable, versioned, tamper-evident record of risk identification changes and management decisions | `iso31000.6.7` | **Versioned library** (`v1 → v2 …`) with immutable version snapshots; non-silent propagation ("Library updated — review changes" banner); explicit acceptance/dismissal logged in append-only trail | Existing: `risk_audit_trail` (append-only SHA-256 hash chain + DB triggers via `2026_08_09_100004`, `action_type` `varchar(64)` via `2026_08_12_120009`). Proposed: `risk_library_item_versions` (immutable snapshots). **Must register** new models in `App\Support\MorphTypes::map()`. | Partial: `risk_audit_trail` is verifiable via `audit:verify`, but needs an examiner-ready version-history + acceptance-log export for a library item and its instances. | **Partial** |
| **COSO ERM 2017** | Principle 14 (*Develops Portfolio View*) — aggregate entity/process risk instances into an enterprise portfolio view by category and compare against risk appetite | `coso.erm.p14` | One-to-many `library_item → risks` roll-up grouped by `risk_categories`, plus **Coverage Matrix** (library risks × entities / processes) | Existing: `risks.category_id → risk_categories`, `risks.parent_risk_id` / `risk_level` (`2026_03_26_000001`), `risk_appetite`, `entities.category_appetites`. Proposed: `risks.library_item_id`. | **Gap today:** Roll-up must guard against double-counting hierarchical `parent_risk_id` / `childRisks` rows and must compare portfolio aggregation against `risk_appetite`, with CSV/PDF export of portfolio & coverage matrix. | **Gap** |
| **Basel II/III (BCBS Annex 9 / OPE25)** | Operational Risk Event Type Classification — 7 Level 1 event types and 20 Level 2 sub-categories | `basel.ope25.event_types` | Filtering and tagging library items (and instances) by Basel II/III Level 1 and Level 2 operational risk event types | **Gap in existing schema:** `risk_categories.basel_category` only stores Pillar 1 umbrellas (`Credit Risk`, `Market Risk`, `Operational Risk`, `Liquidity Risk`); `risk_taxonomies` is unseeded (`SeedSmOpEventTypes` references missing `App\Services\Sm\OpEventTypeCatalogue`); `loss_events` stores `basel_l1_category` / `basel_l2_category` as raw strings. | **Gap:** Neither `risk_categories` nor `risk_taxonomies` can answer a Basel L1/L2 filter today. Must seed canonical Basel L1/L2 taxonomy or add enum-backed `basel_l1_event_type` / `basel_l2_event_type` columns and include them in library & register exports. | **Gap** |
| **CBN ORMS** | Operational Risk Management & Supervisory Reporting (CBN *Guidance Notes on Calculation of Capital Requirement for Operational Risk* & quarterly FinA ORMS operational loss returns) | `cbn.orms.taxonomy` | Taxonomy alignment between Risk Library / Risk Register / RCSA and the Basel L1/L2 event types used in quarterly CBN ORMS loss-event reporting | Existing: `loss_events` (`basel_l1_category`, `basel_l2_category`, `cbn_risk_category`, `cbn_orms_event_type`) → `RegulatoryReportService::generateCbnOrmsReturn()` & `ExportController::lossEventsCbnOrms()`. **Gap:** No shared Basel L1/L2 link between `risk_library_items`/`risks` and `loss_events`. | Partial for Loss Events (CSV exists); **Gap** for Risk Library / Risk Register alignment. Note: CBN ORMS quarterly return is a *loss-event* return; the library provides taxonomy alignment, not the filing itself. | **Gap** |
| **APQC PCF** *(Benchmark — not a legal obligation)* | Process Classification Framework 3-tier hierarchy (Category / Process Group → Process → Sub-Process) with owner, criticality, department/entity | `apqc.pcf.hierarchy` | **Process Module** (Process Groups per department/BU, Processes, Sub-Processes, Process Dashboard) + **Processes tab on `Pages/Scoping/Show.jsx`** | Existing: `business_processes` (`business_unit_id`, `parent_id`, `code`, `name`, `owner_id`, `criticality`, `is_active`). **Gaps:** No `process_groups` table; no `entity_id` on `business_processes`; no unique index on `(organization_id, code)`; `Entity` has no link to `business_units` or `business_processes`. | **Gap:** No process catalogue export or entity-process coverage export exists. Must preserve `business_processes.parent_id = NULL` for top-level Processes so RCSA v2 (`rcsa_register_risks.process_id` / `sub_process_id`) does not break. | **Gap** |
| **BOFIA 2020 / NDIC & ISO 22301:2019 §8.2.2** | Critical service designation vs qualitative process criticality | `bofia.continuity` *(existing)* | Clean boundary between ERM `business_processes.criticality` and BCMS `bcms_processes.criticality_tier` / `is_critical_service` | Existing: `bcms_processes.business_process_id → business_processes.id` (`2026_09_09_120001`). | Met in BCMS (`docs/compliance/cbn-obligations.md` §5). ERM Process Module must **not** overwrite or conflate `bcms_processes.is_critical_service` or BIA-derived `criticality_tier`. | **Met** (boundary to preserve) |
| **CBN Corporate Governance Guidelines 2023 / RCSA Continuity** | Unified risk identification across ERM Risk Register and RCSA Universe without duplicate or drifting definitions | `cbn.governance.board_oversight` *(existing)* | Creating a risk from both Risk Register (`risks`) and RCSA Universe (`rcsa_register_risks`) pointing at the same Risk Library | Existing: `rcsa_register_risks` (`2026_09_06_100003`) has `risk_category` (free string `varchar(64)`) and **no `library_item_id` / `library_version`**. | **Gap:** RCSA cannot currently reference `risk_library_items`. Adding nullable `library_item_id` + `library_version` to `rcsa_register_risks` closes the loop. | **Gap** |

---

## 2. Claims in the Prompt NOT Met by the Current Codebase or Proposed Design

Every item below was verified directly against the repository. The architect must resolve each before implementation begins.

### Gap 1 (Critical): Basel II/III Level 1 & Level 2 Event Types Do NOT Exist in `risk_categories` or `risk_taxonomies`
- **Prompt claim:** *"Every library item carries a category from existing `risk_categories` / `risk_taxonomies`, and the library can be filtered by Basel II/III event type (Level 1 and Level 2), which is what CBN ORMS reporting expects."*
- **Verified reality:**
  1. `risk_categories` (`database/migrations/2026_02_22_200005_create_risk_categories_table.php` and `database/seeders/RiskCategorySeeder.php`) stores Pillar 1 risk umbrellas (`Credit Risk`, `Market Risk`, `Operational Risk`, `Liquidity Risk`) in `basel_category`. Its Operational Risk sub-tree (`OR-PR` Process Risk, `OR-PE` People Risk, `OR-SY` Systems Risk, `OR-EX` External Events) models **Basel operational risk cause classes**, **not** the 7 Basel II/III Level 1 Event Types (`Internal Fraud`, `External Fraud`, `Employment Practices & Workplace Safety`, `Clients, Products & Business Practices`, `Damage to Physical Assets`, `Business Disruption & System Failures`, `Execution, Delivery & Process Management`) or the 20 Level 2 Event Types (BCBS Annex 9 / Basel Framework OPE25).
  2. `risk_taxonomies` (`database/migrations/2026_03_26_000006_create_regulatory_compliance_tables.php`) has **zero seeded Basel L1/L2 rows**. `app/Console/Commands/SeedSmOpEventTypes.php` imports `App\Services\Sm\OpEventTypeCatalogue`, which **does not exist anywhere in the codebase**, and `Database\Seeders\Sm\SmReferenceSeeder` only seeds `ScenarioCategory` and `ScenarioModel`.
  3. Basel L1 event types currently exist only as a PHP array `LossEvent::BASEL_EVENT_TYPES` (`app/Models/LossEvent.php:48`) and free-text `basel_l1_category` / `basel_l2_category` columns on `loss_events`.
- **Remediation for the architect:**
  - Create a single authoritative PHP Enum (or canonical reference catalogue) for the **7 Basel II/III Level 1 Event Types** and **20 Level 2 Event Types** (aligned with `LossEvent::BASEL_EVENT_TYPES` and BCBS Annex 9 / OPE25).
  - Add nullable `basel_l1_event_type` and `basel_l2_event_type` columns (or FK to a seeded Basel L1/L2 tree in `risk_taxonomies` — fixing the broken `SeedSmOpEventTypes` seeder) on `risk_library_items`, and carry them onto `risks` (or read via relation + snapshot).
  - Keep Basel L1/L2 **nullable** because Credit, Market, Liquidity, and Strategic risks in the Risk Library are not Basel Operational Risk event types; validate L2 as a legal child of L1 whenever L1 is set, and prompt/require L1 when the risk's `category_id` falls under Operational Risk (`OR`).
  - **Accuracy note on CBN ORMS:** Do not represent the Risk Library as a CBN ORMS filing. CBN ORMS quarterly returns (`ExportController::lossEventsCbnOrms()`, `RegulatoryReportService::generateCbnOrmsReturn()`) report **loss events**. The Risk Library satisfies **taxonomy alignment** for operational risk identification and RCSA; both Risk Library and Risk Register exports should include `basel_l1_event_type`, `basel_l2_event_type`, and `cbn_mapping_code` so an examiner can reconcile the risk catalogue against the ORMS loss return.

### Gap 2 (Critical): `Entity` (`Pages/Scoping/Show.jsx`) Has No Database Link to `business_processes` or `business_units`
- **Prompt claim:** *"Process module: process groups (per department), processes and sub-processes over existing `business_processes` ... Processes tab on entity drill-down (`Pages/Scoping/Show.jsx`): can add a process or sub-process from the tab."*
- **Verified reality:**
  - `business_processes` (`2026_02_22_200003` + `2026_09_06_100003`) has `business_unit_id` (nullable FK to `business_units`) and `parent_id` (nullable self-FK). It has **NO `entity_id` column**.
  - `entities` (`2026_02_25_100002_create_entities_table.php` and `app/Models/Entity.php`) has **NO foreign key to `business_units`** and **NO `processes()` relationship**.
- **Remediation for the architect:**
  - Decide explicitly between adding nullable `entity_id` to `business_processes` (1:N entity → processes) or an `entity_business_process` pivot (M:N, if a shared process like *Payment Processing* or *Identity & Access Management* spans multiple entities/branches).
  - Document the relationship between `business_unit_id` (used by RCSA v2 and `BusinessProcessSeeder`) and `entity_id` (used by Scoping) so the Processes tab on `Pages/Scoping/Show.jsx` and the Process Dashboard do not show conflicting counts.

### Gap 3 (High): Putting Process Groups into `business_processes` Would Break RCSA v2
- **Prompt claim:** *"Process module: process groups (per department), processes and sub-processes over existing `business_processes`."*
- **Verified reality:**
  - RCSA v2 (`2026_09_06_100003_create_rcsa_universe_tables.php`) added `business_processes.parent_id` specifically for **Process (`parent_id IS NULL`) → Sub-Process (`parent_id IS NOT NULL`)**, and `BusinessProcess::scopeTopLevel()` assumes every `parent_id IS NULL` row is a Process. `rcsa_register_risks` has separate `process_id` and `sub_process_id` FKs to `business_processes`.
  - Additionally, `business_processes` has **no unique index on `(organization_id, code)`** (only `bcms_processes` has one), which allows duplicate process codes.
- **Remediation for the architect:**
  - Model **Process Groups** as a dedicated `process_groups` table (`organization_id`, `business_unit_id`, `entity_id` nullable, `code`, `name`, `description`, `owner_id`, `sort_order`, `is_active`, unique `(organization_id, code)`), with nullable `process_group_id` FK on `business_processes`. This keeps `business_processes.parent_id = NULL` strictly as Level-2 Processes and `parent_id != NULL` as Level-3 Sub-Processes, preserving 100% compatibility with RCSA v2 and `bcms_processes.business_process_id`.
  - **APQC PCF naming accuracy:** APQC PCF's official levels 1–3 are *Category (1.0) → Process Group (1.1) → Process (1.1.1)* (with Level 4 *Activity*). Label the UI/docs honestly as an **"APQC PCF-aligned 3-tier hierarchy (Process Group → Process → Sub-Process)"** unless optional `apqc_pcf_code` is stored.

### Gap 4 (High): Versioned Library Needs Immutable Version Snapshots, Not Just a `version` Integer
- **Prompt claim:** *"Editing a library item creates version n+1; instances keep version n and get 'Library updated — review changes' banner; nothing propagates silently; accepting update is explicit and audited in `risk_audit_trail`."*
- **Verified reality:**
  - Without a dedicated snapshot table (`risk_library_item_versions`), editing a library item overwrites the live row, making it impossible to (a) render an accurate field-by-field diff between version `n` and `n+1` in the review banner, or (b) prove to an examiner under ISO 31000 §6.7 what version `n` said when an instance was previously assessed.
  - Furthermore, `AuditTrailService::record()` calls `$entity->getMorphClass()`, which is enforced by `Relation::enforceMorphMap()` in `App\Support\MorphTypes::map()`. Any new model not added to `MorphTypes::map()` will throw at runtime.
- **Remediation for the architect:**
  - Create an immutable `risk_library_item_versions` table (`library_item_id`, `version`, `title`, `description`, `category_id`, `basel_l1_event_type`, `basel_l2_event_type`, `risk_source`, `typical_causes`, `typical_consequences`, `suggested_controls`, `suggested_kris`, `change_summary`, `created_by`, `created_at` — append-only, no updates).
  - Register `risk_library_item`, `risk_library_item_version`, and `process_group` in `App\Support\MorphTypes::map()`.
  - Keep audit `action_type` names ≤ 64 chars (`RiskAuditTrail::ACTION_TYPE_MAX`), e.g. `library_item_versioned`, `library_update_accepted`, `library_update_dismissed`, `risk_instantiated_from_library`.
  - Ensure accepting a library update updates ONLY definition fields and `library_version` — **never** instance scores, owner, treatment, or review dates.

### Gap 5 (Medium): ISO 31000 §6.4.2 Structured Consequences Missing on `risks`
- **Prompt claim:** *"Library item holds description, taxonomy category, typical causes and consequences, suggested controls, suggested KRIs — no scores."*
- **Verified reality:**
  - `risk_causes` (`2026_08_18_120001`) exists for structured causes on `risks`, **but there is no `risk_consequences` table or consequence column on `risks`** (verified by grep across `database/migrations/` and `app/Models/Risk.php`).
- **Remediation for the architect:**
  - Store `typical_causes` (structured with optional `cause_category_id` referencing `risk_cause_categories`) and `typical_consequences` on `risk_library_items`, and decide whether instantiating a risk into `risks` seeds `risk_causes` rows and adds a `risk_consequences` table (or `consequences` JSON/text column on `risks`) so library consequences are not silently dropped on instantiation.

### Gap 6 (Medium): RCSA Dual-Entry Has No Library Foreign Key Yet
- **Prompt claim:** *"Creating a risk stays possible from both Risk Register and RCSA, both pointing at the same library."*
- **Verified reality:**
  - `rcsa_register_risks` (`2026_09_06_100003`) has no `library_item_id` or `library_version` column, and stores `risk_category` as a free string (`varchar(64)`) rather than `category_id -> risk_categories`.
- **Remediation for the architect:**
  - Add nullable `library_item_id` (FK to `risk_library_items`, `nullOnDelete()`) and `library_version` (`unsignedInteger`, nullable) to `rcsa_register_risks`, and map library `category.name` cleanly into `rcsa_register_risks.risk_category` when instantiating from the library in RCSA.

### Gap 7 (Medium): Examiner "Show Me" Exports Not Specified for New Screens
- **Prompt claim:** Coverage matrix, gap views, library, process dashboard.
- **Compliance rule (`.claude/agents/compliance-analyst.md`):** *"Refuse to approve a feature as evidence-sufficient because it stores the right data, if it cannot export that data in the shape an examiner asks for."*
- **Remediation for the architect:**
  - Add CSV/PDF exports (via `ExportController` / `ThirdLine\Reporting\DocumentRenderer`) for:
    1. **Risk Library Catalogue** (code, title, version, status, category, CBN mapping code, Basel L1/L2, instance count).
    2. **Coverage Matrix & Gap Report** (timestamped snapshot: library risks × entities/processes with Assessed / Missing / Overdue status, plus unmapped processes, unused library risks, and entities with no processes).
    3. **Process Catalogue** (Process Group → Process → Sub-Process, owner, department/entity, criticality, linked risk count).
    4. Extend **`ExportController::riskRegister()`** with `library_code`, `library_version`, `entity_code`, `process_code`, `basel_l1_event_type`, `basel_l2_event_type`.

---

## 3. Checks Against `docs/DEVELOPMENT_STANDARD.md`

No fundamental architectural contradiction exists between the prompt and `docs/DEVELOPMENT_STANDARD.md`, **but nine load-bearing rules in the standard constrain how these four changes MUST be designed**:

| Standard Rule | Constraint on This Feature |
|---|---|
| **§1 Presenters, not fat controllers** | Coverage matrix, gap views, Process Dashboard, and Scoping Processes tab must compute data in Services/Presenters (`app/Services/`, `app/Presenters/`), never in controllers. |
| **§2 Permissions in `RiskPermissionCatalog`** | No `risk_library.*` or `process.*` permissions exist today (only `bcms.process.*` and `rcsa_universe.*`). Declare `risk_library.view`, `risk_library.manage`, `risk_library.apply`, `process.view`, `process.manage` **once** in `App\Authorization\RiskPermissionCatalog` and grant via migration reading the catalog (`RouteAuthorizationTest` & `PermissionCatalogCoversRoutesTest` will fail otherwise). |
| **§3 Model-named Policies** | Add `RiskLibraryItemPolicy`, `ProcessGroupPolicy`, and `BusinessProcessPolicy`. Do **not** place `BusinessProcess` abilities on `EntityPolicy` or `RiskPolicy` (Laravel will silently deny them). |
| **§4 Form Requests & tenant-bound `Rule::exists`** | Zero inline `$request->validate([...])` in controllers. Every FK (`library_item_id`, `category_id`, `entity_id`, `business_unit_id`, `process_group_id`, `parent_id`, `owner_id`) must use `Rule::exists(...)->where('organization_id', $orgId)`. |
| **§5 No fabricated numbers** | In the Coverage Matrix and Process Dashboard, coverage % over an empty set (0 entities or 0 processes) must be `null` / `—` ("undefined, not zero"). Guarded by `NoFabricatedNumbersTest`. |
| **§7 Tenancy (`BelongsToOrganization`)** | The prompt says the Risk Library *"belongs to no department or entity"*. It **must still belong to an `organization_id`** via `BelongsToOrganization` (tenant-isolated). If a system-wide starter library is ever shipped, use nullable `organization_id` with read-only system rows (never cross-tenant editable). |
| **§8 Reuse primitives** | Library index, Process list, and Gap views must use `GridPresenter` + `@thirdline/ui` `DataGrid`. Any JSON canonicalisation uses `App\Support\CanonicalJson`. |
| **§9 Two-submit-button forms** | The "Library updated — review changes" panel (Accept Update vs Keep Current / Dismiss) must use `useRef` + `transform()`, never `setData` + `post()`. |
| **§13 & §14 Timestamps and Date casts** | Event timestamps (`published_at`, `accepted_at`) must be `dateTime()->nullable()` or `timestamp()->nullable()`, never bare `timestamp()` or `useCurrentOnUpdate()` (`NoSelfUpdatingTimestampColumnsTest`). Overdue detection in the Coverage Matrix (`next_review_date`) **must** use `whereDate('next_review_date', '<', now()->toDateString())` — never `->isPast()` or `< now()`, so risks due today are not falsely flagged overdue. |

---

## 4. Examiner Walkthrough ("Show Me" Sufficiency Criteria for QA Gate)

Before this programme is marked compliance-sufficient after QA, the system must answer all five questions in one click:

1. *"Show me your master operational risk catalogue mapped to Basel II/III Level 1 and Level 2 event types."* → Risk Library filtered by Basel L1/L2 + one-click CSV/PDF export.
2. *"Show me every entity and process where 'Payment Settlement Failure' is assessed, where it is missing, and where assessments are overdue."* → Coverage Matrix screen + one-click export (with date-safe overdue logic).
3. *"This risk description changed last quarter — prove it did not silently alter prior risk scores, and show me who accepted the update and when."* → `risk_library_item_versions` snapshot diff + `risk_audit_trail` hash-chained record (`library_update_accepted`) verified by `audit:verify`.
4. *"Show me all critical business processes for Retail Banking and which ones have zero identified risks."* → Process Dashboard + Gap View ("Processes with no risks") + one-click export, without mutating `bcms_processes.is_critical_service`.
5. *"Show me your enterprise portfolio view rolled up by risk category against risk appetite without double-counting child risks."* → Portfolio roll-up respecting `risks.parent_risk_id` / `risk_level`, compared against `risk_appetite`, exportable.

---

## HANDOFF
**Phase:** Pre-Architecture Compliance Review (G0)
**Agent:** compliance-analyst
**Status:** complete
**Delivered:** `docs/compliance/erm-risk-library-process-clause-map.md`
**Clause refs published:** `iso31000.6.4.2`, `iso31000.6.4.3`, `iso31000.6.7`, `coso.erm.p14`, `basel.ope25.event_types`, `cbn.orms.taxonomy`, `apqc.pcf.hierarchy` (plus preserved existing refs `bofia.continuity`, `cbn.governance.board_oversight`)
**Contracts touched:** none (specification only; no code, migrations, or tests written)
**Assumptions made:**
- Risk Library is tenant-scoped (`organization_id` via `BelongsToOrganization`), entity- and department-agnostic within a tenant.
- Basel II/III Level 1 (7) and Level 2 (20) event types follow BCBS *International Convergence of Capital Measurement and Capital Standards* Annex 9 / Basel Framework OPE25.
- CBN ORMS quarterly reporting remains loss-event-based (`loss_events`); the Risk Library provides upstream taxonomy alignment rather than replacing the ORMS filing.
**Known gaps:**
1. Basel II/III L1 & L2 event types do not exist in `risk_categories` or `risk_taxonomies` today (`SeedSmOpEventTypes` references missing `App\Services\Sm\OpEventTypeCatalogue`).
2. `business_processes` has no `entity_id` and `Entity` has no link to `business_units` or `business_processes` (blocks Scoping Processes tab until schema decision is made).
3. Storing Process Groups inside `business_processes` would break RCSA v2's `parent_id = NULL` invariant; a separate `process_groups` table is recommended.
4. Immutable `risk_library_item_versions` snapshots + `MorphTypes::map()` registration are required for true version diffing and `risk_audit_trail` compatibility.
5. `risks` has no structured `consequences` column/table for ISO 31000 §6.4.2 library consequences.
6. `rcsa_register_risks` has no `library_item_id` / `library_version` for RCSA dual-entry.
7. Examiner exports are needed for Risk Library, Coverage Matrix / Gap Views, Process Catalogue, and extended Risk Register.
**Next agent:** architect (to design the schema, migrations, models, services, presenters, and export shapes resolving Gaps 1–7 and adhering to `docs/DEVELOPMENT_STANDARD.md` §1–§15)
**Verification run:** Inspected `docs/DEVELOPMENT_STANDARD.md`, `.claude/agents/compliance-analyst.md`, `docs/compliance/cbn-obligations.md`, `docs/compliance/iso22301-clause-map.md`, `database/migrations/` (`risk_categories`, `risk_taxonomies`, `risks`, `business_processes`, `bcms_processes`, `rcsa_universe_tables`, `risk_audit_trail`, `make_risk_audit_trail_append_only`, `widen_audit_trail_action_type`, `assessment_chain_tables`, `entities`, `add_risk_hierarchy_fields`), `database/seeders/` (`RiskCategorySeeder`, `BusinessProcessSeeder`, `DatabaseSeeder`, `SmReferenceSeeder`), `app/Models/` (`Risk`, `RiskTaxonomy`, `BusinessProcess`, `Entity`, `BusinessUnit`, `LossEvent`, `RiskAuditTrail`, `RcsaRegisterRisk`), `app/Services/` (`AuditTrailService`, `RegulatoryReportService`, `Sm/ScenarioService`), `app/Console/Commands/SeedSmOpEventTypes.php`, `app/Support/MorphTypes.php`, `app/Authorization/RiskPermissionCatalog.php`, `app/Http/Controllers/Risk/` (`ExportController`, `ScopingController`), and `resources/js/Pages/Scoping/Show.jsx`. Examiner walkthrough identified 7 concrete gaps to resolve at architecture time.
