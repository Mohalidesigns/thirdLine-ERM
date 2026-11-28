# Screen Spec — Process Dashboard & Process Catalogue (`Processes/Dashboard`, `Processes/Index`)

**Phase:** P2 · **Routes:**
- `GET /risk/processes/dashboard` → `risk.processes.dashboard` (`ProcessController@dashboard`, `permission:process.view`)
- `GET /risk/processes` → `risk.processes.index` (`ProcessController@index`, `permission:process.view`)
- `GET /risk/processes/{process}` → `risk.processes.show` (`ProcessController@show`, `permission:process.view`)
- `GET /risk/processes/export?format=csv|pdf&...` → `risk.processes.export` (`permission:process.view`)
**Policy:** `App\Policies\BusinessProcessPolicy` (`viewAny`, `view`, `create`, `update`).
**Compliance Boundary (ADR 0001 & ADR 0028):** Displays ERM `business_processes.criticality` only; **never** mutates or conflates `bcms_processes.is_critical_service` or BIA-derived `criticality_tier`.

---

## 1. Purpose & User

Used by `chief-risk-officer`, `risk-manager`, `risk-analyst`, `risk-owner`, `compliance-officer`, `board-member`, and `risk-champion` to get an enterprise view of the APQC PCF-aligned 3-tier process hierarchy (`Process Group → Process → Sub-Process`), spot high-criticality processes with zero linked risks (Examiner Question 4), and export the Process Catalogue in one click.

---

## 2. Inertia Props & Endpoint Contracts (ADR 0028)

### 2A. `Processes/Dashboard` (`ProcessDashboardPresenter`)
```json
{
  "kpis": {
    "total_processes": 28,
    "total_sub_processes": 64,
    "total_groups": 6,
    "critical_processes": 7,
    "unmapped_processes_count": 4,
    "risk_coverage_pct": 85.7
  },
  "byGroup": [
    {
      "group_id": 2,
      "group_code": "PG-002",
      "group_name": "Payment Clearing & Settlement",
      "business_unit_name": null,
      "processes_count": 5,
      "sub_processes_count": 12,
      "linked_risks_count": 14,
      "unmapped_count": 1
    }
  ],
  "byCriticality": [
    { "criticality": "critical", "count": 7 },
    { "criticality": "high", "count": 10 },
    { "criticality": "medium", "count": 8 },
    { "criticality": "low", "count": 3 }
  ],
  "unmappedCritical": [
    { "id": 19, "code": "PRC-019", "name": "SWIFT Wire Reconciliation", "group_name": "Payment Clearing & Settlement", "department_name": "Operations", "owner_name": "Seyi B.", "criticality": "critical" }
  ],
  "can": { "create": true, "manageGroups": true }
}
```
*Standard §5 rule for `kpis.risk_coverage_pct`:* Computed server-side as `round(($processesWithAtLeastOneRisk / $totalProcesses) * 100, 1)` **only when `$totalProcesses > 0`**; MUST be `null` when `$totalProcesses === 0`.

### 2B. `Processes/Index` (`ProcessesGrid` via `GridPresenter`)
```json
{
  "counts": { "processes": 28, "sub_processes": 64, "unmapped": 4 },
  "grid": { /* Standard GridPresenter payload */ },
  "filterOptions": {
    "groups": [{ "id": 2, "code": "PG-002", "name": "Payment Clearing & Settlement" }],
    "businessUnits": [{ "id": 1, "name": "Retail Banking" }],
    "owners": [{ "id": 14, "name": "Tunde A." }],
    "criticalities": ["critical", "high", "medium", "low"]
  },
  "can": { "create": true, "update": true, "manageGroups": true }
}
```
- **Row shape in `grid.rows`:** `id`, `code`, `apqc_code` (nullable), `name`, `is_sub_process` (bool, `parent_id !== null`), `parent_name` (nullable), `process_group_name` (or `null` → `"Ungrouped"`), `business_unit_name` (or `null` → `"Enterprise / Cross-Departmental"`), `entities_count` (int via `entity_business_process`), `owner_name`, `criticality` (`critical|high|medium|low|null`), `sub_processes_count` (int), `linked_risks_count` (int), `is_active` (bool).
- **Export (`GET /risk/processes/export?format=csv|pdf&...`):** Exports current filtered Process Catalogue (`group_code`, `group_name`, `process_code`, `apqc_code`, `process_name`, `parent_code`, `department`, `owner`, `criticality`, `sub_processes_count`, `linked_risks_count`, `status`).

---

## 3. Layout

### 3A. `Processes/Dashboard`
1. **`PageHeader`**:
   - Title: `Process Dashboard`
   - Subtitle: `APQC PCF-aligned hierarchy · {kpis.total_groups} groups · {kpis.total_processes} processes · {kpis.total_sub_processes} sub-processes`
   - Actions: `Export Catalogue CSV` / `PDF` (`btn-secondary`), `Process Groups` (`Link` to `risk.process-groups.index`), `New Process` (`btn-primary`, opens Create modal/page when `can.create`).
2. **KPI Tile Row** (`grid grid-cols-2 lg:grid-cols-5 gap-4 mb-6`, using `@thirdline/ui` `KpiCard`):
   - `Processes` (`kpis.total_processes`, icon `schema`, color `primary`)
   - `Sub-Processes` (`kpis.total_sub_processes`, icon `account_tree`, color `info`)
   - `Critical Processes` (`kpis.critical_processes`, icon `priority_high`, color `danger`)
   - `Processes with No Risks` (`kpis.unmapped_processes_count`, icon `warning`, color `warning`, clickable link to `risk.coverage.index?tab=gaps` in P4 or `risk.processes.index?filters[has_risks]=none`)
   - `Risk Coverage` (`${kpis.risk_coverage_pct}%`, `unavailable={kpis.risk_coverage_pct === null}`, `unavailableLabel="Not assessed"`, icon `verified_user`, color `success`) — **Standard §5**: never `0%` when `total_processes === 0`.
3. **Two-Column Breakdown** (`grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6`):
   - **Left (`lg:col-span-2`) — Processes by Group Card:** Table with columns `Process Group` (shows `"Enterprise / Cross-Departmental"` muted badge when `business_unit_name === null`, per ADR 0028 Decision 5), `Processes`, `Sub-Processes`, `Linked Risks`, `No Risks` (amber pill if `> 0`). Clicking a group navigates to `risk.processes.index?filters[process_group_id]={id}`.
   - **Right (`lg:col-span-1`) — Criticality Breakdown Card:** `@thirdline/ui` `DonutChart` (or inline SVG horizontal bars, zero external chart library) showing counts by criticality; hidden and replaced by `"No processes classified yet."` when `total_processes === 0`.
4. **Attention Table — Critical / High Processes with Zero Linked Risks** (`card`):
   - Lists `unmappedCritical` rows with direct `View Process` link. If empty (and `total_processes > 0`), shows Forest green confirmation `"All critical processes have at least one linked risk."`

### 3B. `Processes/Index` (Process Catalogue)
1. **`PageHeader`**: Title `Process Catalogue`, subtitle `{counts.processes} processes · {counts.sub_processes} sub-processes`, actions: `Export CSV`, `Export PDF`, `New Process` (`btn-primary`, `can.create`).
2. **Quick-Filter Pills:** `Top-Level Processes ({counts.processes})` (default `filters[level]=process` so the list mirrors RCSA v2's `parent_id IS NULL` view), `Sub-Processes ({counts.sub_processes})`, `No Linked Risks ({counts.unmapped})`, `All`.
3. **Filter Bar (`useGridState`):** Search (`code`, `apqc_code`, `name`), `Process Group` `<select>` (includes `Ungrouped` option), `Department` `<select>` (includes `Enterprise / Cross-Departmental`), `Owner` `<select>`, `Criticality` `<select>`, `Status` (`Active` / `Inactive`).
4. **`@thirdline/ui` `DataGrid` Columns:**
   1. **Code** (`code` + muted `APQC {apqc_code}` sub-line if present; `Link` to `risk.processes.show`)
   2. **Process Name** (if sub-process, shows muted parent breadcrumb `↳ {parent_name}`)
   3. **Process Group** (`process_group_name ?? 'Ungrouped'`)
   4. **Department** (`business_unit_name ?? 'Enterprise / Cross-Departmental'`)
   5. **Owner** (`owner_name ?? '—'`)
   6. **Criticality** (`RatingBadge` / colour pill: Critical red, High orange, Medium amber, Low green, `—` if null)
   7. **Sub-Processes** (integer count `sub_processes_count`; `—` on sub-process rows)
   8. **Linked Risks** (integer count `linked_risks_count`; amber `0` badge when `0` to highlight gap)
   9. **Actions:** `View`, `Edit` (when `can.update`), `+ Sub-Process` (on top-level process rows when `can.create`).

---

## 4. Interactive Flows

- Clicking `New Process` or `Edit` opens the Process Create/Edit slide-over or page (`process-create-edit.md`).
- Clicking `+ Sub-Process` on a top-level process row opens the Create slide-over pre-locked to `level = 'sub_process'` and `parent_id = row.id`.
- Export CSV/PDF links append the active `grid.state.filters` query string so examiners receive the exact filtered catalogue.

---

## 5. States

| State | Behaviour |
|---|---|
| **Loading** | Grid dims (`opacity-60`, `aria-busy="true"`) during filter/pagination visits; KPI cards retain prior values. |
| **Empty (zero processes)** | Dashboard KPI `Risk Coverage` renders `"Not assessed"` (`unavailable={true}`); Catalogue renders `EmptyState` (`"No business processes registered yet."`) with `New Process` CTA (if `can.create`). |
| **Empty (filtered)** | `"No processes match the current filters."` + `Clear filters` button. |
| **Error** | Top `role="alert"` banner with retry link; preserves existing table state. |
| **Permission Denied** | HTTP 403 without `process.view`. Roles without `process.create`/`process.update` (`compliance-officer`, `board-member`, `risk-champion`) see read-only views and export buttons only. |
| **Low-Bandwidth (100 kbps)** | Dashboard uses inline SVG / `@thirdline/ui` `DonutChart` (no Chart.js bundle); Catalogue paginates at 25 rows/page via `GridPresenter`; CSV/PDF exports stream directly via browser download. |

---

## 6. WCAG 2.1 AA Accessibility

- Criticality badges pair colour with text labels (`Critical`, `High`, `Medium`, `Low`) — never colour-only dots.
- `KpiCard` with `unavailable={true}` renders semantic text `"Not assessed"` (not a blank cell or ambiguous dash alone).
- Table headers use `<th scope="col">`; filter selects have explicit `<label htmlFor>`.

---

## 7. Data Provenance & Standard §5 Compliance

| Displayed Figure | Authoritative Source | Empty / Null Rule |
|---|---|---|
| `total_processes` | `COUNT(*)` on `business_processes` where `parent_id IS NULL` & `is_active = true` | `0` is a true count |
| `total_sub_processes` | `COUNT(*)` on `business_processes` where `parent_id IS NOT NULL` & `is_active = true` | `0` is a true count |
| `unmapped_processes_count` | Active top-level processes with `0` rows in `risks` (`process_id`) | `0` is a true count |
| `risk_coverage_pct` | `(total_processes - unmapped_processes_count) / total_processes * 100` | `null` → `"Not assessed"` when `total_processes === 0` (never `0%`) |
| `linked_risks_count` | `COUNT(*)` on `risks` where `process_id = bp.id` (org-scoped) | `0` is a true count |
