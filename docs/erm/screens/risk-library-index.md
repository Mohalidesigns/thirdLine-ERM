# Screen Spec — Risk Library Index (`RiskLibrary/Index`)

**Phase:** P1 · **Route:** `GET /risk/library` → `risk.library.index` · **Controller:** `RiskLibraryController@index`
**Middleware:** `permission:risk_library.view` · **Policy:** `App\Policies\RiskLibraryItemPolicy::viewAny`
**Siblings to match:** `resources/js/Pages/Register/Index.jsx`, `App\Grids\Definitions\RisksGrid`

---

## 1. Purpose & User

Used by `chief-risk-officer`, `risk-manager`, `risk-analyst`, `risk-owner`, `compliance-officer`, `board-member`, and `risk-champion` to browse, filter, govern, and export the organisation's master risk catalogue (ISO 31000 §6.4.2; Basel II/III OPE25). Within the first ten seconds the user must see: how many active vs retired master definitions exist, filter to a taxonomy category or Basel II/III L1/L2 event type, export an examiner-ready CSV/PDF, or launch Bulk Apply / Create.

---

## 2. Inertia Props & Endpoint Contracts (ADR 0028)

Shaped by `RiskLibraryGrid` (`GridPresenter` + `GridQuery`/`GridState`) and `RiskLibraryPresenter`:

```json
{
  "counts": { "active": 42, "retired": 3, "total": 45 },
  "grid": { /* Standard GridPresenter payload: columns, rows, state, pagination, filters */ },
  "filterOptions": {
    "categories": [{ "id": 1, "code": "OR-PR", "name": "Process Risk" }],
    "baselL1": [{ "value": "execution_delivery", "label": "Execution, Delivery & Process Management" }],
    "baselL2": [{ "value": "transaction_capture", "label": "Transaction Capture, Execution & Maintenance", "l1": "execution_delivery" }]
  },
  "can": {
    "create": true,
    "update": true,
    "retire": true,
    "bulkApply": true,
    "export": true
  }
}
```

- **Row shape in `grid.rows`:** `id`, `code` (`RL-0001`), `title`, `category_name`, `basel_l1_label` (or `null`), `basel_l2_label` (or `null`), `current_version` (int), `status` (`'active' | 'retired'`), `instances_count` (int, `COUNT` of linked `risks` rows in org), `updated_at` (ISO-8601).
- **Endpoints:**
  - `GET /risk/library` (`risk.library.index`, `permission:risk_library.view`) — server-filtered/paginated grid via query params `filters[status]`, `filters[category_id]`, `filters[basel_l1]`, `filters[basel_l2]`, `search`, `sort`, `page`.
  - `GET /risk/library/create` (`risk.library.create`, `permission:risk_library.create`) — Create screen.
  - `GET /risk/library/{item}` (`risk.library.show`, `permission:risk_library.view`) — Detail & version history.
  - `GET /risk/library/export?format=csv|pdf&...` (`risk.library.export`, `permission:risk_library.view`) — streams current filtered view via `ExportController` / `ThirdLine\Reporting\DocumentRenderer` (includes `code`, `title`, `version`, `status`, `category`, `cbn_mapping_code`, `basel_l1_event_type`, `basel_l2_event_type`, `instances_count`).
  - Bulk Apply drawer (P3) posts to `POST /risk/library/bulk-apply/preview` and `POST /risk/library/bulk-apply` (`permission:risk.create`) — see `add-from-library-picker.md`.

---

## 3. Layout

Shell: `AuthenticatedLayout` (`title="Risk Library"`), `<Head title="Risk Library" />`.

1. **`PageHeader`**:
   - Title: `Risk Library`
   - Subtitle: `{counts.active} active definitions · {counts.retired} retired`
   - Actions (right-aligned, gated by `can`):
     - Export dropdown/button group (`btn-secondary`, `download` icon): `Export CSV` and `Export PDF` (plain `<a>` anchors carrying current query string so downloads work without JS overhead).
     - `Bulk Apply` (`btn-secondary`, `library_add` icon) — visible when `can.bulkApply` (P3); opens the Bulk Apply slide-over pre-loaded with any checked rows or blank item selector.
     - `New Library Risk` (`btn-primary`, `add_circle` icon) → `Link` to `risk.library.create` when `can.create`.
2. **Status Quick-Filter Pills** (same pattern as `Register/Index.jsx`, `bg-white rounded-lg border border-gray-200 p-1 w-fit mb-4`):
   - `Active ({counts.active})` (default on initial load: `filters[status]=active`)
   - `Retired ({counts.retired})`
   - `All ({counts.total})`
   - Active pill uses `bg-[#1A365D] text-white`; inactive uses `text-gray-600 hover:bg-gray-100`.
3. **Filter Bar** (wired to `useGridState(grid)`):
   - Search input (matches `code`, `title`, `description`; debounced 350 ms).
   - `Category` `<select>` (`filterOptions.categories`).
   - `Basel L1 Event Type` `<select>` (`filterOptions.baselL1`).
   - `Basel L2 Event Type` `<select>` — filtered client-side to children of selected L1 (`item.l1 === selectedL1`); disabled with placeholder `Select Basel L1 first` when L1 is empty.
   - `Clear filters` text button shown when any filter or search is active.
4. **`@thirdline/ui` `DataGrid`** (`grid={grid}`):
   - Columns (above the fold):
     1. **Code** (`RL-xxxx`, `font-semibold text-[#1A365D]`, `Link` to `risk.library.show`)
     2. **Risk Title** (plain text, truncated to 2 lines with full title in `title` attribute; zero scores shown anywhere)
     3. **Category** (`category_name`)
     4. **Basel Event Type** (`basel_l1_label` + muted sub-line `basel_l2_label`; renders `—` when both are `null`, e.g. Credit/Market risks)
     5. **Version** (`v{current_version}` mono pill `bg-gray-100 text-gray-700`)
     6. **Instances** (integer count `instances_count`, including `0`; links to `risk.library.show#instances` when `> 0`)
     7. **Status** (`StatusBadge`: `active` → Forest green, `retired` → neutral slate)
     8. **Actions**: `View` link; `Apply to Entities` trigger (active items only, when `can.bulkApply`); `Edit` link (when `can.update`). No Delete action exists (ADR 0028 Decision 6).

---

## 4. Interactive Flows

- **Filtering & pagination:** Every pill, select, sort header, and page control updates URL query params via `gridState.update(...)` (Inertia `GET` with `preserveState: true`, `preserveScroll: true`). Changing Basel L1 automatically clears `filters[basel_l2]` in the same `gridState.update` call.
- **Export CSV / PDF:** Clicking `Export CSV` or `Export PDF` triggers a direct GET download carrying the active filter parameters (`status`, `category_id`, `basel_l1`, `basel_l2`, `search`) so the examiner receives the exact filtered slice on screen.
- **Bulk Apply trigger:** Clicking row-level `Apply` opens the Bulk Apply slide-over pre-selected to that single library item; clicking header `Bulk Apply` opens it with multi-item selection enabled.

---

## 5. States

| State | Behaviour |
|---|---|
| **Loading** | During Inertia filter/page visits, `DataGrid` body dims (`opacity-60 pointer-events-none`) with a top progress bar and `aria-busy="true"` on the grid region; previous rows remain visible (no blank flash). |
| **Empty (zero items in org)** | `@thirdline/ui` `EmptyState`: title `"No risks in the library yet"`, body `"Build your master risk catalogue to reuse consistent risk definitions across entities and processes."`, primary CTA `New Library Risk` (only if `can.create`; read-only roles see `"Contact your Risk Manager to add library definitions."`). |
| **Empty (filtered)** | Inside grid body: `"No library items match the current filters."` with a `Clear filters` button. |
| **Error** | Flash banner (`bg-red-50 border-red-200 text-red-800`, `role="alert"`) if an export or navigation fails, with a `Retry` button; never clears the currently visible table. |
| **Permission Denied** | Users without `risk_library.view` receive HTTP 403 (`Errors/403`) and do not see `Risk Library` in the sidebar (`NavPresenter`). Users with `risk_library.view` only (`risk-owner`, `compliance-officer`, `board-member`, `risk-champion`) see no Create/Edit/Retire controls. |
| **Low-Bandwidth (100 kbps)** | Server-side pagination capped at 25 rows/page; no `typical_causes`, `typical_consequences`, `suggested_controls`, or `suggested_kris` JSON columns are sent in the index payload. Filter selects are native `<select>` elements (zero extra JS chunks). Export links use standard browser downloads without buffering blobs in JS memory. |

---

## 6. WCAG 2.1 AA Accessibility

- **Keyboard path:** Skip link → `PageHeader` actions → Status pills (`role="group" aria-label="Filter by status"`, `aria-pressed={active}`) → Filter controls (explicit `<label htmlFor>`) → `DataGrid` sortable headers (`aria-sort`) and row links → Pagination.
- **Contrast:** Navy `#1A365D` on white (`11.9:1`), white on `#1A365D` (`11.9:1`), Forest `#2D7D46` badge text on `bg-green-50` (`≥ 4.5:1`), retired slate `text-gray-700` on `bg-gray-100` (`≥ 7:1`).
- **Screen reader:** Live region (`aria-live="polite"`) announces `"Showing {from}–{to} of {total} library items"` after filter/search updates.

---

## 7. Data Provenance & Standard §5 Compliance

| Displayed Figure | Authoritative Source | Empty / Null Rule |
|---|---|---|
| `counts.active`, `counts.retired`, `counts.total` | `COUNT(*)` on `risk_library_items` scoped by `organization_id` | `0` is a true count (`?? 0` permitted) |
| `current_version` | `risk_library_items.current_version` | Always integer `≥ 1` |
| `instances_count` | `COUNT(*)` on `risks` where `library_item_id = item.id` (org-scoped) | `0` is a true count; never shows a percentage |
| Basel L1 / L2 | `BaselEventTypeL1` / `BaselEventTypeL2` enum labels | Renders `—` when `null` |
