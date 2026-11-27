# Screen Spec — Coverage Matrix, Gap Views & RCSA Universe Library Entry

**Phase:** P4 · **Routes:**
- `GET /risk/coverage` → `risk.coverage.index` (`CoverageMatrixController@index`, `permission:risk_library.view`) — hosts Coverage Matrix, Gap Views, and Portfolio Roll-Up tabs
- `GET /risk/coverage/export?tab=matrix|gaps|portfolio&axis=entities|processes&format=csv|pdf&...` → `risk.coverage.export` (`permission:risk_library.view`)
- `POST /risk/coverage/attach` → `risk.coverage.attach` (`permission:risk.create`) — inline attach on a Missing cell
- `Pages/RcsaUniverse/AddRiskPanel.jsx` (extended) — offers **Pick from Risk Library** first on Step 2 (`Risk`), writing nullable `library_item_id` + `library_version` onto `rcsa_register_risks` (`POST /risk/rcsa-universe`, existing permission `rcsa_universe.manage`)
**Standard §14 Overdue Rule (MANDATORY):** A risk instance is Overdue **iff** `next_review_date` is non-null AND `whereDate('next_review_date', '<', now()->toDateString())` — never `->isPast()` or `< now()`, so risks due today are never falsely flagged overdue.

---

## 1. Purpose & User

Answers Examiner Walkthrough Questions 2, 4, and 5 in one click (`docs/compliance/erm-risk-library-process-clause-map.md` §4):
1. **Coverage Matrix:** *"Which entities / processes have assessed each library risk, which are missing it, and which are overdue?"* — with inline attach on any Missing cell.
2. **Gap Views:** Three actionable exception lists — Processes with no risks, Library risks used nowhere, and Entities with no processes.
3. **Portfolio Roll-Up (COSO ERM Principle 14):** Enterprise aggregation by `risk_categories` compared against `risk_appetite`, guarding against `parent_risk_id` double-counting.
4. **RCSA Universe `AddRiskPanel.jsx`:** Lets risk champions pick a master risk from the Risk Library first (pre-filling `potential_risk`, `risk_driver`, `risk_category`, `library_item_id`, `library_version`) while preserving the keyboard-first `Save & Add Another` loop and bespoke entry fallback.

---

## 2. Inertia Props & Endpoint Contracts (ADR 0028)

### 2A. `Coverage/Index` (`CoverageMatrixPresenter`)
```json
{
  "activeTab": "matrix",
  "axis": "entities",
  "summary": {
    "library_items_count": 20,
    "targets_count": 8,
    "total_cells": 160,
    "assessed_count": 94,
    "overdue_count": 12,
    "missing_count": 54,
    "coverage_pct": 66.3,
    "overdue_rate_pct": 11.3
  },
  "matrix": {
    "columns": [{ "id": 3, "code": "ENT-003", "name": "Retail Banking" }],
    "rows": [
      {
        "library_item_id": 12,
        "code": "RL-0012",
        "title": "Payment Settlement Failure",
        "category_name": "Process Risk",
        "cells": {
          "3": { "status": "assessed", "risk_id": 91, "risk_code": "RSK-0091", "residual_rating": "high", "next_review_date": "2027-01-15", "url": "/risk/register/91" },
          "4": { "status": "overdue", "risk_id": 98, "risk_code": "RSK-0098", "residual_rating": "critical", "next_review_date": "2026-10-01", "url": "/risk/register/98" },
          "7": { "status": "missing" }
        }
      }
    ],
    "pagination": { "page": 1, "per_page": 25, "total_rows": 20, "col_page": 1, "col_per_page": 12, "total_cols": 8 }
  },
  "gaps": {
    "processes_without_risks": { "count": 4, "rows": [/* id, code, name, group_name, department_name, owner_name, criticality */] },
    "unused_library_risks": { "count": 3, "rows": [/* id, code, title, category_name, current_version */] },
    "entities_without_processes": { "count": 2, "rows": [/* id, entity_code, name, type, owner_name */] }
  },
  "portfolio": [
    {
      "category_id": 4,
      "category_name": "Process Risk",
      "top_level_risks_count": 18,
      "excluded_child_risks_count": 5,
      "max_residual_score": 20,
      "appetite_threshold": 15,
      "appetite_status": "above_appetite"
    }
  ],
  "filterOptions": {
    "categories": [{ "id": 4, "name": "Process Risk" }],
    "baselL1": [{ "value": "execution_delivery", "label": "Execution, Delivery & Process Management" }]
  },
  "can": { "attachRisk": true, "export": true }
}
```
*Standard §5 rules on `summary` and `portfolio`:*
- `coverage_pct` = `(assessed_count + overdue_count) / total_cells * 100` — MUST be `null` when `total_cells === 0`.
- `overdue_rate_pct` = `overdue_count / (assessed_count + overdue_count) * 100` — MUST be `null` when `assessed_count + overdue_count === 0`.
- `portfolio[].max_residual_score` = `null` when `top_level_risks_count === 0` or all unrated (renders `Not assessed`, never `0`).
- `portfolio[].appetite_status` = `'not_configured'` when no `risk_appetite` row exists for the category (never fabricates a threshold).

### 2B. RCSA Universe `AddRiskPanel.jsx` Additions
- `options.libraryItems` (or lazy `GET /risk/library/picker-options?active_only=1` when Step 2 opens): active library items (`id`, `code`, `title`, `description`, `category_name`, `typical_causes`, `current_version`).
- Form state adds `library_item_id: ''` and `library_version: ''`.
- `POST /risk/rcsa-universe` / `PUT /risk/rcsa-universe/{risk}` accepts nullable `library_item_id` (tenant-bound `Rule::exists`) and `library_version`.

---

## 3. Layout

### 3A. `Coverage/Index`
1. **`PageHeader`**:
   - Title: `Coverage Matrix & Gap Analysis`
   - Subtitle: `Active Risk Library coverage across entities and business processes`
   - Actions: `Export CSV` and `Export PDF` (`btn-secondary` links carrying active `tab`, `axis`, and filters).
2. **Top-Level Tabs** (`border-b border-gray-200 bg-white px-6 rounded-t-xl mb-6`):
   - `Coverage Matrix` (`matrix`)
   - `Gap Views ({gaps_total})` (`gaps`)
   - `Portfolio Roll-Up` (`portfolio`)
3. **Tab 1 — Coverage Matrix:**
   - **KPI Strip** (`grid-cols-2 lg:grid-cols-4 gap-4 mb-4`):
     - `Coverage Rate`: `${summary.coverage_pct}%` (`unavailable={summary.coverage_pct === null}`, `unavailableLabel="Not assessed"`)
     - `Assessed (Current)`: `summary.assessed_count`
     - `Overdue Reviews`: `summary.overdue_count` (amber/red tone if `> 0`)
     - `Missing Cells`: `summary.missing_count`
   - **Toolbar:**
     - Axis segmented toggle: `By Entity` (`axis=entities`) vs `By Process` (`axis=processes`).
     - Filters: `Category` `<select>`, `Basel L1` `<select>`, `Cell Status` (`All`, `Has Missing`, `Has Overdue`), Search (`code`/`title`).
     - Legend (explicit colour + icon + text): Forest green `✓ Assessed`, Amber/Red `! Overdue`, Neutral dashed `+ Missing`.
   - **Sticky-First-Column Matrix Table** (`overflow-x-auto bg-white rounded-xl border border-gray-200`):
     - Sticky left column (`sticky left-0 bg-white z-10 min-w-[240px]`): `RL-xxxx` + title + category.
     - Column headers: Entity or Process `code` + `name` (max 12 columns per column-page with `< Prev / Next >` column pager when `total_cols > 12`).
     - **Cell rendering:**
       - `assessed`: clickable link pill (`bg-green-50 text-[#2D7D46] border border-green-200`) showing `✓` + `residual_rating` (or `Unrated`). Clicking navigates to `cell.url` (`Register/Show`).
       - `overdue`: clickable link pill (`bg-red-50 text-red-700 border border-red-200`) showing `! Overdue` + due date tooltip. Clicking navigates to `cell.url`.
       - `missing`: dashed neutral button (`border border-dashed border-gray-300 text-gray-500 hover:border-[#1A365D] hover:text-[#1A365D]`) reading `+ Attach` (when `can.attachRisk`) or plain `—` (read-only roles).
   - **Inline Attach Popover / Mini-Drawer (on Missing cell click):**
     - Anchored slide-over (`max-w-md`) pre-filled with `{library_code} — {library_title}` and target `{Entity or Process}`.
     - Optional `Process` (when axis is `entities`) and `Risk Owner` `<select>`.
     - Submit `Attach Risk` (`btn-primary`) → `POST /risk/coverage/attach` → cell flips immediately to `Assessed (Unrated)` via Inertia reload (`preserveScroll: true`).
4. **Tab 2 — Gap Views:**
   Three stacked `card` tables (each with count pill and one-click `Export CSV` anchor):
   - **1. Processes with No Risks (`gaps.processes_without_risks`):** Columns `Code`, `Process Name`, `Group`, `Department`, `Owner`, `Criticality`, Action `Add Risk` (opens picker for that process).
   - **2. Library Risks Used Nowhere (`gaps.unused_library_risks`):** Columns `Code`, `Title`, `Category`, `Version`, Action `Bulk Apply` (opens Bulk Apply slide-over pre-selected to that item).
   - **3. Entities with No Processes (`gaps.entities_without_processes`):** Columns `Code`, `Entity Name`, `Type`, `Owner`, Action `Manage Processes` (`Link` to `risk.scoping.show?tab=processes`).
5. **Tab 3 — Portfolio Roll-Up:**
   - Info banner explaining COSO ERM Principle 14 de-duplication: *"Child risks linked via `parent_risk_id` are excluded from category totals to prevent double-counting."*
   - Table: `Risk Category`, `Top-Level Risks`, `Excluded Child Risks`, `Peak Residual Score` (`{score}/25` or `Not assessed`), `Risk Appetite Threshold` (`{threshold}/25` or `Not configured`), `Appetite Status` (`Within Appetite` Forest green / `Above Appetite` red / `Not configured` slate).

### 3B. RCSA Universe `AddRiskPanel.jsx` — Pick from Risk Library First (Step 2: `Risk`)
- At the top of Step 2 (`step === 'risk'`), before `Potential Risk`, render a compact **Source Toggle** (`Pick from Risk Library` [default on create] vs `Enter Custom Risk`):
  - **Pick from Risk Library mode:** Searchable combobox/select of active library items (`code — title`, filterable by text). Selecting an item sets `library_item_id`, `library_version`, and pre-fills `potential_risk` (from `title` + `description`), `risk_driver` (from first `typical_causes`), and `risk_category` (from `category_name`). All pre-filled fields remain editable for RCSA context, and a small badge `Linked to {code} (v{version})` with a `Detach` link stays visible.
  - **Save & Add Another preservation:** Pressing `Ctrl+Enter` / `Save & Add Another` preserves `business_unit_id`, `process_id`, `sub_process_id`, clears `library_item_id`, `library_version`, and risk/control fields, and returns focus to the Library picker input (or `Potential Risk` if in custom mode).

---

## 4. Interactive Flows

- **Switching Axis (`By Entity` ↔ `By Process`):** Inertia `GET` with `axis=processes&col_page=1` (`preserveState: true`).
- **Inline Attach:** Single-click `+ Attach` → optional owner → `Attach Risk` → creates unrated instance (`risk_instantiated_from_library` audit log), preserves matrix scroll and pagination.
- **Exports:** Direct GET anchors streaming timestamped CSV or PDF via `ThirdLine\Reporting\DocumentRenderer` so examiners can download the exact filtered Matrix, Gap Report, or Portfolio Roll-Up in one click.

---

## 5. States

| State | Behaviour |
|---|---|
| **Loading** | Matrix body dims (`opacity-60`, `aria-busy="true"`) during axis/filter/page navigation; inline attach button shows `"Attaching…"`. |
| **Empty (no library items OR no entities/processes)** | `Coverage Rate` renders `"Not assessed"` (`unavailable={true}`); matrix area renders `EmptyState` stating specifically what is missing (`"Add active risks to the Risk Library"` or `"Register entities/processes first"`) with direct CTA links. |
| **Empty (each Gap View)** | Zero-gap card shows Forest green check: `"All active processes have at least one linked risk."` / `"Every active library risk is used in at least one entity or process."` / `"All active entities have at least one linked process."` |
| **Error** | Top `role="alert"` banner with retry action; inline attach errors render inside the mini-drawer without losing matrix position. |
| **Permission Denied** | HTTP 403 without `risk_library.view`. Read-only roles (`compliance-officer`, `board-member`, `risk-champion`) see `—` instead of `+ Attach` on missing cells and no mutation CTAs on Gap Views, while retaining full CSV/PDF export access. |
| **Low-Bandwidth (100 kbps)** | Matrix paginates **both** rows (25/page) and columns (12/page) server-side so payloads stay < 20 KB even for banks with 200 library risks × 100 branches. `gaps` and `portfolio` payloads are only computed/sent when their tab is active (lazy Inertia partial/tab query `?tab=gaps`), never bloated onto the matrix response. |

---

## 6. WCAG 2.1 AA Accessibility

- Matrix table uses `<th scope="col">` for entities/processes and `<th scope="row">` for library risks so screen readers announce both coordinates per cell.
- Every cell communicates status via **icon + text + colour** (`✓ Assessed`, `! Overdue`, `+ Attach` / `Missing`) — never colour alone.
- Missing-cell buttons carry full accessible names: `aria-label="Attach {RL-0012 Payment Settlement Failure} to {Retail Banking}"`.
- RCSA `AddRiskPanel.jsx` preserves full keyboard-only flow (`Tab`, `Ctrl+Enter`, `Escape`) with explicit `<label>` on the library selector.

---

## 7. Data Provenance & Standard §5 Compliance

| Displayed Figure | Authoritative Source | Empty / Null Rule |
|---|---|---|
| `summary.coverage_pct` | `(assessed_count + overdue_count) / total_cells * 100` | `null` → `"Not assessed"` when `total_cells === 0` (never `0%`) |
| `summary.overdue_count` | `risks` with `whereDate('next_review_date', '<', now()->toDateString())` (Standard §14) | `0` is a true count |
| `gaps.*.count` | Exact org-scoped `COUNT` queries in `CoverageMatrixService` | `0` is a true count |
| `portfolio[].max_residual_score` | `MAX(residual_score)` over top-level (`parent_risk_id IS NULL`) risks in category | `null` → `"Not assessed"` (never `0/25`) |
| `portfolio[].appetite_threshold` | `risk_appetite` table for org + category | `null` → `"Not configured"` |
